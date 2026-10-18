<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Finance\Models\BillingAccount;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\InvoiceItem;
use App\Domain\Finance\Models\InvoiceSequence;
use App\Domain\Governance\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues and voids invoices. Numbers are unique per tenant and fiscal year
 * (sequence row locked while incrementing). Invoices are never deleted:
 * voiding keeps the record with its reason, and is only possible while
 * nothing has been paid against it.
 */
class ManageInvoices
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function accountFor(Student $student): BillingAccount
    {
        $account = BillingAccount::query()->where('tenant_id', $student->tenant_id)->where('student_id', $student->id)->first();

        if ($account !== null) {
            return $account;
        }

        $account = new BillingAccount(['student_id' => $student->id, 'currency' => (string) config('finance.currency', 'GEL')]);
        $account->tenant_id = $student->tenant_id;
        $account->save();

        return $account;
    }

    /**
     * @param  array<int, array{description: string, quantity: int, unit_minor: int, source_type?: string|null, source_id?: int|null}>  $items
     */
    public function issue(BillingAccount $account, array $items, Carbon $dueOn, User $actor): Invoice
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'ინვოისს სულ მცირე ერთი პოზიცია სჭირდება.']);
        }

        foreach ($items as $index => $item) {
            if ($item['quantity'] < 1 || $item['unit_minor'] < 1) {
                throw ValidationException::withMessages(["items.{$index}.unit_minor" => 'რაოდენობა და ფასი დადებითი უნდა იყოს.']);
            }
        }

        $issuedOn = Carbon::now('Asia/Tbilisi');

        if ($dueOn->lt($issuedOn->copy()->startOfDay())) {
            throw ValidationException::withMessages(['due_on' => 'გადახდის ვადა წარსულში ვერ იქნება.']);
        }

        return DB::transaction(function () use ($account, $items, $dueOn, $actor, $issuedOn) {
            $year = (int) $issuedOn->format('Y');
            $number = $this->nextNumber($account->tenant_id, $year);

            $invoice = new Invoice([
                'billing_account_id' => $account->id,
                'fiscal_year' => $year,
                'number' => sprintf('%d-%06d', $year, $number),
                'status' => Invoice::STATUS_ISSUED,
                'issued_on' => $issuedOn->toDateString(),
                'due_on' => $dueOn->toDateString(),
                'currency' => $account->currency,
                'total_minor' => 0,
                'paid_minor' => 0,
                'credited_minor' => 0,
                'created_by' => $actor->id,
            ]);
            $invoice->tenant_id = $account->tenant_id;
            $invoice->save();

            $total = 0;

            foreach ($items as $item) {
                $amount = $item['quantity'] * $item['unit_minor'];
                $total += $amount;

                $line = new InvoiceItem([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_minor' => $item['unit_minor'],
                    'amount_minor' => $amount,
                    'source_type' => $item['source_type'] ?? null,
                    'source_id' => $item['source_id'] ?? null,
                ]);
                $line->tenant_id = $account->tenant_id;
                $line->save();
            }

            $invoice->total_minor = $total;
            $invoice->save();

            $this->auditLogger->record($account->tenant_id, 'invoice.issued', $invoice, $actor->id, [
                'number' => $invoice->number,
                'total_minor' => $total,
                'currency' => $invoice->currency,
            ]);

            return $invoice;
        });
    }

    public function void(Invoice $invoice, string $reason, User $actor): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'გაუქმების მიზეზი სავალდებულოა.']);
        }

        DB::transaction(function () use ($invoice, $reason, $actor) {
            $locked = Invoice::query()->where('tenant_id', $invoice->tenant_id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Invoice::STATUS_VOID) {
                return;
            }

            if ($locked->paid_minor > 0 || $locked->allocations()->whereNull('reversed_at')->exists()) {
                throw ValidationException::withMessages(['reason' => 'გადახდილი ინვოისი ვერ გაუქმდება — გამოწერეთ საკრედიტო ნოტა.']);
            }

            if ($locked->credited_minor > 0) {
                throw ValidationException::withMessages(['reason' => 'ინვოისზე საკრედიტო ნოტა უკვე გამოწერილია — გაუქმება შეუძლებელია.']);
            }

            $locked->status = Invoice::STATUS_VOID;
            $locked->void_reason = trim($reason);
            $locked->voided_at = Carbon::now();
            $locked->save();

            $this->auditLogger->record($locked->tenant_id, 'invoice.voided', $locked, $actor->id, ['number' => $locked->number, 'reason' => trim($reason)]);
        });

        $invoice->refresh();
    }

    private function nextNumber(int $tenantId, int $year): int
    {
        $sequence = InvoiceSequence::query()->where('tenant_id', $tenantId)->where('fiscal_year', $year)->lockForUpdate()->first();

        if ($sequence === null) {
            $sequence = new InvoiceSequence(['fiscal_year' => $year, 'last_number' => 0]);
            $sequence->tenant_id = $tenantId;
            $sequence->save();
            $sequence = InvoiceSequence::query()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
        }

        $sequence->last_number = $sequence->last_number + 1;
        $sequence->save();

        return $sequence->last_number;
    }
}
