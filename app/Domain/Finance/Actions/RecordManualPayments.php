<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\BillingAccount;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\Refund;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bookkeeping without a bank API: the accountant records money that
 * arrived by cash, bank transfer or POS, from the cash book or the bank
 * statement.
 *
 * - A form submission carries an idempotency key; the same key never
 *   records a second payment (double click, retried request).
 * - A bank/POS document reference can be recorded once per method; a
 *   second entry of the same statement line is refused.
 * - The amount is applied to the chosen invoice, or to the account's open
 *   invoices oldest due date first; the rest stays as account credit.
 * - A wrongly entered payment is reversed with a reason, never deleted:
 *   its allocations are stamped reversed and the invoices reopened.
 */
class RecordManualPayments
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ProcessPayments $payments,
    ) {}

    public function record(
        BillingAccount $account,
        int $amountMinor,
        string $method,
        Carbon $receivedOn,
        ?string $reference,
        ?string $note,
        ?int $invoiceId,
        string $idempotencyKey,
        User $actor,
    ): Payment {
        $reference = $reference !== null && trim($reference) !== '' ? trim($reference) : null;
        $providerPaymentId = 'manual-'.$idempotencyKey;

        if ($amountMinor < 1) {
            throw ValidationException::withMessages(['amount' => 'თანხა დადებითი უნდა იყოს.']);
        }

        if (! in_array($method, Payment::METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'გადახდის მეთოდი არასწორია.']);
        }

        if ($reference === null && in_array($method, [Payment::METHOD_BANK_TRANSFER, Payment::METHOD_POS], true)) {
            throw ValidationException::withMessages(['reference' => 'საბანკო/POS გადახდას საბუთის ნომერი სჭირდება.']);
        }

        if ($receivedOn->copy()->startOfDay()->gt(Carbon::now('Asia/Tbilisi')->startOfDay())) {
            throw ValidationException::withMessages(['received_on' => 'გადახდის თარიღი მომავალში ვერ იქნება.']);
        }

        $existing = $this->byIdempotencyKey($account->tenant_id, $providerPaymentId);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($account, $amountMinor, $method, $receivedOn, $reference, $note, $invoiceId, $providerPaymentId, $actor) {
                if ($reference !== null && Payment::query()->where('tenant_id', $account->tenant_id)
                    ->where('provider', Payment::PROVIDER_MANUAL)->where('method', $method)->where('reference', $reference)
                    ->whereNull('reversed_at')->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['reference' => 'ამ საბუთის ნომრით გადახდა უკვე გატარებულია.']);
                }

                $targets = $this->targetInvoices($account, $invoiceId);

                $payment = new Payment([
                    'billing_account_id' => $account->id,
                    'provider' => Payment::PROVIDER_MANUAL,
                    'provider_payment_id' => $providerPaymentId,
                    'method' => $method,
                    'reference' => $reference,
                    'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
                    'recorded_by' => $actor->id,
                    'amount_minor' => $amountMinor,
                    'currency' => $account->currency,
                    'unallocated_minor' => $amountMinor,
                    'refunded_minor' => 0,
                    'reversed_minor' => 0,
                    // Noon Tbilisi keeps the booked calendar day stable in UTC.
                    'received_at' => $receivedOn->copy()->setTimezone('Asia/Tbilisi')->setTime(12, 0)->utc(),
                ]);
                $payment->tenant_id = $account->tenant_id;
                $payment->save();

                $allocatedTo = [];

                foreach ($targets as $invoice) {
                    $amount = min($payment->unallocated_minor, $invoice->outstandingMinor());

                    if ($amount <= 0) {
                        break;
                    }

                    $this->payments->allocateLocked($payment, $invoice, $amount, $actor->id);
                    $allocatedTo[] = ['invoice_id' => $invoice->id, 'amount_minor' => $amount];
                }

                $this->auditLogger->record($account->tenant_id, 'payment.manual_recorded', $payment, $actor->id, [
                    'method' => $method,
                    'amount_minor' => $amountMinor,
                    'currency' => $payment->currency,
                    'allocations' => $allocatedTo,
                    'credit_minor' => $payment->unallocated_minor,
                ]);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent submission with the same key won the race.
            return $this->byIdempotencyKey($account->tenant_id, $providerPaymentId)
                ?? throw ValidationException::withMessages(['amount' => 'გადახდა ვერ გატარდა, სცადეთ თავიდან.']);
        }
    }

    public function reverse(Payment $payment, string $reason, User $actor): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'გაუქმების მიზეზი სავალდებულოა.']);
        }

        DB::transaction(function () use ($payment, $reason, $actor) {
            $locked = Payment::query()->where('tenant_id', $payment->tenant_id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isManual()) {
                throw ValidationException::withMessages(['reason' => 'ბანკის მიერ დადასტურებული გადახდა ხელით არ უქმდება.']);
            }

            if ($locked->isReversed()) {
                return;
            }

            if ($locked->refunded_minor > 0) {
                throw ValidationException::withMessages(['reason' => 'ამ გადახდიდან თანხა უკვე დაბრუნდა — გაუქმება შეუძლებელია.']);
            }

            $allocations = PaymentAllocation::query()->where('tenant_id', $locked->tenant_id)
                ->where('payment_id', $locked->id)->active()->orderBy('invoice_id')->lockForUpdate()->get();

            foreach ($allocations as $allocation) {
                $invoice = Invoice::query()->where('tenant_id', $locked->tenant_id)->whereKey($allocation->invoice_id)->lockForUpdate()->firstOrFail();
                $invoice->paid_minor = max(0, $invoice->paid_minor - $allocation->amount_minor);

                if ($invoice->status !== Invoice::STATUS_VOID) {
                    $invoice->status = $invoice->statusFromPaid();
                }

                $invoice->save();

                $allocation->reversed_at = Carbon::now();
                $allocation->save();
            }

            $locked->reversed_minor = $locked->amount_minor - $locked->refunded_minor;
            $locked->unallocated_minor = 0;
            $locked->reversed_at = Carbon::now();
            $locked->reversal_reason = mb_substr(trim($reason), 0, 500);
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'payment.manual_reversed', $locked, $actor->id, [
                'amount_minor' => $locked->amount_minor,
                'reopened_invoices' => $allocations->pluck('invoice_id')->all(),
                'reason' => $locked->reversal_reason,
            ]);
        });

        $payment->refresh();
    }

    /**
     * Account credit paid back to the family by hand (cash or transfer).
     * Final immediately: the accountant records money that already left.
     */
    public function refundCredit(Payment $payment, int $amountMinor, string $method, string $reason, User $actor): Refund
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'დაბრუნების მიზეზი სავალდებულოა.']);
        }

        if (! in_array($method, [Payment::METHOD_CASH, Payment::METHOD_BANK_TRANSFER], true)) {
            throw ValidationException::withMessages(['method' => 'დაბრუნების მეთოდი არასწორია.']);
        }

        return DB::transaction(function () use ($payment, $amountMinor, $method, $reason, $actor) {
            $locked = Payment::query()->where('tenant_id', $payment->tenant_id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isReversed() || $amountMinor < 1 || $amountMinor > $locked->unallocated_minor) {
                throw ValidationException::withMessages(['amount' => 'დასაბრუნებელი თანხა აღემატება ანგარიშის კრედიტს.']);
            }

            $locked->unallocated_minor -= $amountMinor;
            $locked->refunded_minor += $amountMinor;
            $locked->save();

            $refund = new Refund([
                'payment_id' => $locked->id,
                'amount_minor' => $amountMinor,
                'currency' => $locked->currency,
                'reason' => mb_substr(trim($reason), 0, 500),
                'provider_refund_id' => null,
                'status' => 'succeeded',
                'method' => $method,
                'requested_by' => $actor->id,
            ]);
            $refund->tenant_id = $locked->tenant_id;
            $refund->save();

            $this->auditLogger->record($locked->tenant_id, 'refund.manual_recorded', $refund, $actor->id, [
                'payment_id' => $locked->id,
                'amount_minor' => $amountMinor,
                'method' => $method,
            ]);

            return $refund;
        });
    }

    private function byIdempotencyKey(int $tenantId, string $providerPaymentId): ?Payment
    {
        return Payment::query()->where('tenant_id', $tenantId)
            ->where('provider', Payment::PROVIDER_MANUAL)->where('provider_payment_id', $providerPaymentId)->first();
    }

    /**
     * Locked open invoices to apply the payment to, in order.
     *
     * @return array<int, Invoice>
     */
    private function targetInvoices(BillingAccount $account, ?int $invoiceId): array
    {
        if ($invoiceId !== null) {
            $invoice = Invoice::query()->where('tenant_id', $account->tenant_id)->whereKey($invoiceId)->lockForUpdate()->first();

            if ($invoice === null || $invoice->billing_account_id !== $account->id) {
                throw ValidationException::withMessages(['invoice_id' => 'ინვოისი ამ მოსწავლის ანგარიშს არ ეკუთვნის.']);
            }

            if (! $invoice->isOpen()) {
                throw ValidationException::withMessages(['invoice_id' => 'ინვოისი ღია აღარ არის.']);
            }

            return [$invoice];
        }

        return Invoice::query()->where('tenant_id', $account->tenant_id)->where('billing_account_id', $account->id)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->orderBy('due_on')->orderBy('id')->lockForUpdate()->get()->all();
    }
}
