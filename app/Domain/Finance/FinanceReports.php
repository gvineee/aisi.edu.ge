<?php

namespace App\Domain\Finance;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read models for the accountant: debtors list, invoice register and the
 * payments journal. Every query is filtered by tenant_id explicitly (these
 * also run for exports).
 */
class FinanceReports
{
    public const METHOD_LABELS = [
        'cash' => 'ნაღდი',
        'bank_transfer' => 'საბანკო გადარიცხვა',
        'pos' => 'POS ტერმინალი',
        'other' => 'სხვა',
    ];

    public const STATUS_LABELS = [
        'issued' => 'გადასახდელი',
        'partially_paid' => 'ნაწილობრივ გადახდილი',
        'paid' => 'გადახდილი',
        'void' => 'გაუქმებული',
        'credited' => 'საკრედიტო ნოტით დახურული',
    ];

    /**
     * One row per student with an open balance, most overdue first.
     *
     * @return list<array{accountId: int, studentId: int|null, studentName: string|null, className: string|null, currency: string, openInvoices: int, outstandingMinor: int, overdueMinor: int, oldestDueOn: string|null, daysOverdue: int, creditMinor: int, guardians: list<array{name: string, email: string}>}>
     */
    public function debtors(int $tenantId, ?int $schoolClassId, bool $overdueOnly, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now('Asia/Tbilisi'))->toDateString();

        $open = Invoice::query()->where('tenant_id', $tenantId)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->when($schoolClassId !== null, fn ($query) => $query->whereHas('account.student', fn ($q) => $q->where('school_class_id', $schoolClassId)))
            ->with('account.student.schoolClass')->get()
            ->groupBy('billing_account_id');

        $credit = Payment::query()->where('tenant_id', $tenantId)->whereIn('billing_account_id', $open->keys())
            ->selectRaw('billing_account_id, sum(unallocated_minor) as aggregate')->groupBy('billing_account_id')
            ->pluck('aggregate', 'billing_account_id');

        $studentIds = $open->map(fn (Collection $invoices) => $invoices->first()->account->student_id)->values();
        $guardians = GuardianLink::query()->where('tenant_id', $tenantId)->whereIn('student_id', $studentIds)
            ->where('is_active', true)->where('can_view_financial', true)->with('guardian')->get()->groupBy('student_id');

        $rows = [];

        foreach ($open as $accountId => $invoices) {
            /** @var Invoice $first */
            $first = $invoices->first();
            $student = $first->account->student;
            $overdue = $invoices->filter(fn (Invoice $i) => $i->due_on->toDateString() < $today);
            $overdueMinor = (int) $overdue->sum(fn (Invoice $i) => $i->outstandingMinor());

            if ($overdueOnly && $overdueMinor === 0) {
                continue;
            }

            $guardianRows = [];

            foreach ($guardians->get($student?->id, collect()) as $link) {
                if ($link->guardian !== null) {
                    $guardianRows[] = ['name' => $link->guardian->name, 'email' => $link->guardian->email];
                }
            }

            $oldest = $invoices->min(fn (Invoice $i) => $i->due_on->toDateString());
            $oldestOverdue = $overdue->min(fn (Invoice $i) => $i->due_on->toDateString());

            $rows[] = [
                'accountId' => (int) $accountId,
                'studentId' => $student?->id,
                'studentName' => $student?->fullName(),
                'className' => $student?->schoolClass?->name,
                'currency' => $first->currency,
                'openInvoices' => $invoices->count(),
                'outstandingMinor' => (int) $invoices->sum(fn (Invoice $i) => $i->outstandingMinor()),
                'overdueMinor' => $overdueMinor,
                'oldestDueOn' => is_string($oldest) ? $oldest : null,
                'daysOverdue' => is_string($oldestOverdue) ? (int) Carbon::parse($oldestOverdue)->diffInDays(Carbon::parse($today)) : 0,
                'creditMinor' => (int) ($credit[$accountId] ?? 0),
                'guardians' => $guardianRows,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['overdueMinor'], $b['outstandingMinor']] <=> [$a['overdueMinor'], $a['outstandingMinor']]);

        return $rows;
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function invoiceRegister(int $tenantId, Carbon $from, Carbon $to): Collection
    {
        return Invoice::query()->where('tenant_id', $tenantId)
            ->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])
            ->with('account.student.schoolClass')->orderBy('issued_on')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    public function paymentsJournal(int $tenantId, Carbon $from, Carbon $to, ?string $method = null): Collection
    {
        return Payment::query()->where('tenant_id', $tenantId)
            ->whereBetween('received_at', [
                $from->copy()->setTimezone('Asia/Tbilisi')->startOfDay()->utc(),
                $to->copy()->setTimezone('Asia/Tbilisi')->endOfDay()->utc(),
            ])
            ->when($method !== null, fn ($query) => $query->where('method', $method))
            ->with('account.student.schoolClass')->orderBy('received_at')->orderBy('id')->get();
    }

    public static function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    public static function methodLabel(?string $method, string $provider): string
    {
        return $method === null ? "ონლაინ ({$provider})" : (self::METHOD_LABELS[$method] ?? $method);
    }
}
