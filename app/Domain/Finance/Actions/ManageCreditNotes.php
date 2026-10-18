<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\CreditNote;
use App\Domain\Finance\Models\CreditNoteSequence;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Credit notes: correcting an issued invoice, including one that is
 * already (partly) paid, which voiding cannot do.
 *
 * - A credit note lowers what the invoice asks for (credited_minor), at
 *   most down to zero, and keeps its own number series.
 * - If more was paid than the invoice now asks for, the excess goes back
 *   to account credit: the newest allocations are released first. A
 *   released allocation is stamped reversed and, when partly kept, a
 *   replacement allocation carries the kept part — so the reconciliation
 *   formula (payment = active allocations + credit + refunded + reversed)
 *   holds. The credit can then be paid back or applied to another invoice.
 * - Payments are locked before the invoice, in id order, like every other
 *   path that locks both (no deadlock with allocateCredit).
 */
class ManageCreditNotes
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function issue(Invoice $invoice, int $amountMinor, string $reason, string $idempotencyKey, User $actor): CreditNote
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'კორექტირების მიზეზი სავალდებულოა.']);
        }

        if ($amountMinor < 1) {
            throw ValidationException::withMessages(['amount' => 'თანხა დადებითი უნდა იყოს.']);
        }

        $existing = $this->byIdempotencyKey($invoice->tenant_id, $idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($invoice, $amountMinor, $reason, $idempotencyKey, $actor) {
                $paymentIds = PaymentAllocation::query()->where('tenant_id', $invoice->tenant_id)
                    ->where('invoice_id', $invoice->id)->active()->distinct()->orderBy('payment_id')->pluck('payment_id');
                $payments = Payment::query()->where('tenant_id', $invoice->tenant_id)->whereIn('id', $paymentIds)
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                $locked = Invoice::query()->where('tenant_id', $invoice->tenant_id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === Invoice::STATUS_VOID) {
                    throw ValidationException::withMessages(['amount' => 'გაუქმებულ ინვოისზე საკრედიტო ნოტა არ გამოიწერება.']);
                }

                if ($amountMinor > $locked->netMinor()) {
                    throw ValidationException::withMessages(['amount' => 'თანხა აღემატება ინვოისის დარჩენილ (კორექტირებულ) ჯამს.']);
                }

                $allocations = PaymentAllocation::query()->where('tenant_id', $locked->tenant_id)
                    ->where('invoice_id', $locked->id)->active()->orderByDesc('id')->lockForUpdate()->get();

                if ($allocations->contains(fn (PaymentAllocation $a) => ! $payments->has($a->payment_id))) {
                    // A payment was applied between the two reads; the user can simply retry.
                    throw ValidationException::withMessages(['amount' => 'ინვოისი ამ დროს შეიცვალა, სცადეთ თავიდან.']);
                }

                $issuedOn = Carbon::now('Asia/Tbilisi');
                $year = (int) $issuedOn->format('Y');

                $locked->credited_minor += $amountMinor;
                $toRelease = max(0, $locked->paid_minor - $locked->netMinor());
                $released = [];

                foreach ($allocations as $allocation) {
                    if ($toRelease <= 0) {
                        break;
                    }

                    $take = min($toRelease, $allocation->amount_minor);
                    $payment = $payments->get($allocation->payment_id);

                    $allocation->reversed_at = Carbon::now();
                    $allocation->save();

                    if ($take < $allocation->amount_minor) {
                        $kept = new PaymentAllocation([
                            'payment_id' => $allocation->payment_id,
                            'invoice_id' => $allocation->invoice_id,
                            'amount_minor' => $allocation->amount_minor - $take,
                            'allocated_by' => $actor->id,
                        ]);
                        $kept->tenant_id = $allocation->tenant_id;
                        $kept->save();
                    }

                    $payment->unallocated_minor += $take;
                    $payment->save();

                    $locked->paid_minor -= $take;
                    $toRelease -= $take;
                    $released[] = ['payment_id' => $payment->id, 'amount_minor' => $take];
                }

                $locked->status = $locked->statusFromPaid();
                $locked->save();

                $creditNote = new CreditNote([
                    'invoice_id' => $locked->id,
                    'billing_account_id' => $locked->billing_account_id,
                    'fiscal_year' => $year,
                    'number' => sprintf('CN-%d-%06d', $year, $this->nextNumber($locked->tenant_id, $year)),
                    'issued_on' => $issuedOn->toDateString(),
                    'currency' => $locked->currency,
                    'amount_minor' => $amountMinor,
                    'released_minor' => (int) array_sum(array_column($released, 'amount_minor')),
                    'reason' => mb_substr($reason, 0, 500),
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $actor->id,
                ]);
                $creditNote->tenant_id = $locked->tenant_id;
                $creditNote->save();

                $this->auditLogger->record($locked->tenant_id, 'credit_note.issued', $creditNote, $actor->id, [
                    'number' => $creditNote->number,
                    'invoice_id' => $locked->id,
                    'invoice_number' => $locked->number,
                    'amount_minor' => $amountMinor,
                    'currency' => $creditNote->currency,
                    'released_to_credit' => $released,
                    'reason' => $creditNote->reason,
                ]);

                return $creditNote;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent submission with the same key won the race.
            return $this->byIdempotencyKey($invoice->tenant_id, $idempotencyKey)
                ?? throw ValidationException::withMessages(['amount' => 'საკრედიტო ნოტა ვერ გამოიწერა, სცადეთ თავიდან.']);
        }
    }

    private function byIdempotencyKey(int $tenantId, string $key): ?CreditNote
    {
        return CreditNote::query()->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
    }

    private function nextNumber(int $tenantId, int $year): int
    {
        $sequence = CreditNoteSequence::query()->where('tenant_id', $tenantId)->where('fiscal_year', $year)->lockForUpdate()->first();

        if ($sequence === null) {
            $sequence = new CreditNoteSequence(['fiscal_year' => $year, 'last_number' => 0]);
            $sequence->tenant_id = $tenantId;
            $sequence->save();
            $sequence = CreditNoteSequence::query()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
        }

        $sequence->last_number = $sequence->last_number + 1;
        $sequence->save();

        return $sequence->last_number;
    }
}
