<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\PaymentClaim;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "I have paid" notices without a bank integration. A guardian reports a
 * transfer (with the bank's reference) or cash paid at the school; the
 * accountant checks it against the statement and either confirms it —
 * which books an ordinary manual payment allocated to the invoice, exactly
 * as if typed in by hand (RecordManualPayments: duplicate references,
 * idempotency, audit) — or rejects it with a reason the guardian sees.
 * A notice alone never changes what is owed.
 */
class ManagePaymentClaims
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RecordManualPayments $manualPayments,
    ) {}

    public function submit(Invoice $invoice, User $guardian, int $amountMinor, string $method, Carbon $paidOn, ?string $reference, ?string $note): PaymentClaim
    {
        $reference = $reference !== null && trim($reference) !== '' ? mb_substr(trim($reference), 0, 120) : null;

        if (! $invoice->isOpen()) {
            throw ValidationException::withMessages(['amount' => 'ეს ინვოისი უკვე დახურულია.']);
        }

        if ($amountMinor < 1 || $amountMinor > $invoice->outstandingMinor()) {
            throw ValidationException::withMessages(['amount' => 'თანხა 0-ზე მეტი და დარჩენილ დავალიანებაზე ნაკლები ან ტოლი უნდა იყოს.']);
        }

        if (! in_array($method, PaymentClaim::METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'გადახდის საშუალება არასწორია.']);
        }

        if ($method !== 'cash' && $reference === null) {
            throw ValidationException::withMessages(['reference' => 'საბანკო გადარიცხვას გადახდის ნომერი (reference) სჭირდება.']);
        }

        // Calendar days in the school's timezone.
        if ($paidOn->toDateString() > Carbon::now('Asia/Tbilisi')->toDateString()) {
            throw ValidationException::withMessages(['paid_on' => 'გადახდის თარიღი მომავალში ვერ იქნება.']);
        }

        $pending = PaymentClaim::query()->where('tenant_id', $invoice->tenant_id)->where('invoice_id', $invoice->id)->where('status', PaymentClaim::STATUS_PENDING)->sum('amount_minor');

        if ($pending + $amountMinor > $invoice->outstandingMinor()) {
            throw ValidationException::withMessages(['amount' => 'ამ ინვოისზე უკვე არის განსახილველი შეტყობინება ამ თანხაზე.']);
        }

        $claim = new PaymentClaim([
            'invoice_id' => $invoice->id,
            'submitted_by' => $guardian->id,
            'amount_minor' => $amountMinor,
            'currency' => $invoice->currency,
            'method' => $method,
            'paid_on' => $paidOn->toDateString(),
            'reference' => $reference,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            'status' => PaymentClaim::STATUS_PENDING,
        ]);
        $claim->tenant_id = $invoice->tenant_id;
        $claim->save();

        $this->auditLogger->record($invoice->tenant_id, 'payment_claim.submitted', $claim, $guardian->id, [
            'invoice_id' => $invoice->id, 'amount_minor' => $amountMinor, 'method' => $method,
        ]);

        return $claim;
    }

    public function confirm(PaymentClaim $claim, User $accountant, ?int $amountMinor = null): PaymentClaim
    {
        return DB::transaction(function () use ($claim, $accountant, $amountMinor) {
            $locked = PaymentClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);
            $invoice = Invoice::query()->whereKey($locked->invoice_id)->with('account')->firstOrFail();
            // The accountant books what the statement shows, which may differ.
            $amount = $amountMinor ?? $locked->amount_minor;

            $payment = $this->manualPayments->record(
                account: $invoice->account,
                amountMinor: $amount,
                method: $locked->method,
                receivedOn: Carbon::parse($locked->paid_on->toDateString(), 'Asia/Tbilisi'),
                reference: $locked->reference,
                note: "მშობლის შეტყობინება #{$locked->id}",
                invoiceId: $invoice->id,
                idempotencyKey: "claim-{$locked->id}",
                actor: $accountant,
            );

            $locked->update([
                'status' => PaymentClaim::STATUS_CONFIRMED,
                'reviewed_by' => $accountant->id,
                'reviewed_at' => now(),
                'payment_id' => $payment->id,
                'review_note' => $amount !== $locked->amount_minor ? 'დადასტურდა ამონაწერის თანხით.' : null,
            ]);

            $this->auditLogger->record($locked->tenant_id, 'payment_claim.confirmed', $locked, $accountant->id, [
                'payment_id' => $payment->id, 'claimed_minor' => $locked->amount_minor, 'booked_minor' => $amount,
            ]);

            return $locked;
        });
    }

    public function reject(PaymentClaim $claim, User $accountant, string $reason): PaymentClaim
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['review_note' => 'მიუთითეთ მიზეზი — მშობელი მას დაინახავს.']);
        }

        return DB::transaction(function () use ($claim, $accountant, $reason) {
            $locked = PaymentClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);
            $locked->update([
                'status' => PaymentClaim::STATUS_REJECTED,
                'reviewed_by' => $accountant->id,
                'reviewed_at' => now(),
                'review_note' => mb_substr($reason, 0, 500),
            ]);

            $this->auditLogger->record($locked->tenant_id, 'payment_claim.rejected', $locked, $accountant->id);

            return $locked;
        });
    }

    private function assertPending(PaymentClaim $claim): void
    {
        if ($claim->status !== PaymentClaim::STATUS_PENDING) {
            throw ValidationException::withMessages(['claim' => 'ეს შეტყობინება უკვე განხილულია.']);
        }
    }
}
