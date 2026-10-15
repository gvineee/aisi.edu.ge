<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Finance\Actions\NotifyInvoiceGuardians;
use App\Domain\Finance\FinanceReports;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\AuthorizesFinance;
use App\Support\CsvDownload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Debtors list, payment reminders to families, and spreadsheet exports.
 * Every export is audited (CLAUDE.md #7).
 */
class FinanceReportController extends Controller
{
    use AuthorizesFinance;

    public function debtors(Request $request, CurrentTenant $currentTenant, FinanceReports $reports): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());
        [$classId, $overdueOnly] = $this->filters($request, $tenant->id);

        $rows = $reports->debtors($tenant->id, $classId, $overdueOnly);

        return Inertia::render('portal/finance/debtors', [
            'filters' => ['class' => $classId, 'overdue' => $overdueOnly],
            'classes' => SchoolClass::query()->where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name']),
            'rows' => $rows,
            'totals' => [
                'outstandingMinor' => array_sum(array_column($rows, 'outstandingMinor')),
                'overdueMinor' => array_sum(array_column($rows, 'overdueMinor')),
                'students' => count($rows),
            ],
        ]);
    }

    public function exportDebtors(Request $request, CurrentTenant $currentTenant, FinanceReports $reports, AuditLogger $audit): HttpResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());
        [$classId, $overdueOnly] = $this->filters($request, $tenant->id);
        $rows = $reports->debtors($tenant->id, $classId, $overdueOnly);
        $date = Carbon::now('Asia/Tbilisi')->toDateString();

        $audit->record($tenant->id, 'finance.exported', $tenant, $request->user()->id, ['report' => 'debtors', 'rows' => count($rows)]);

        return CsvDownload::make("debtors-{$date}.csv",
            ['მოსწავლე', 'კლასი', 'ღია ინვოისები', 'დავალიანება', 'ვადაგადაცილებული', 'დღე', 'კრედიტი', 'მშობლები'],
            array_map(fn (array $r) => [
                $r['studentName'],
                $r['className'],
                $r['openInvoices'],
                FinanceReports::money($r['outstandingMinor']),
                FinanceReports::money($r['overdueMinor']),
                $r['daysOverdue'],
                FinanceReports::money($r['creditMinor']),
                implode('; ', array_map(fn (array $g) => "{$g['name']} <{$g['email']}>", $r['guardians'])),
            ], $rows));
    }

    public function exportInvoices(Request $request, CurrentTenant $currentTenant, FinanceReports $reports, AuditLogger $audit): HttpResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());
        $now = Carbon::now('Asia/Tbilisi');
        $from = $this->date($request->query('from')) ?? $now->copy()->startOfMonth();
        $to = $this->date($request->query('to')) ?? $now->copy()->endOfMonth();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > 400) {
            $from = $to->copy()->subDays(400);
        }

        $rows = $reports->invoiceRegister($tenant->id, $from, $to);
        $audit->record($tenant->id, 'finance.exported', $tenant, $request->user()->id, ['report' => 'invoices', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows->count()]);

        return CsvDownload::make("invoices-{$from->toDateString()}-{$to->toDateString()}.csv",
            ['ნომერი', 'გამოწერა', 'ვადა', 'მოსწავლე', 'კლასი', 'სულ', 'გადახდილი', 'დარჩენილი', 'სტატუსი', 'ვალუტა'],
            $rows->map(fn (Invoice $i) => [
                $i->number,
                $i->issued_on->toDateString(),
                $i->due_on->toDateString(),
                $i->account?->student?->fullName(),
                $i->account?->student?->schoolClass?->name,
                FinanceReports::money($i->total_minor),
                FinanceReports::money($i->paid_minor),
                FinanceReports::money($i->status === Invoice::STATUS_VOID ? 0 : $i->outstandingMinor()),
                FinanceReports::STATUS_LABELS[$i->status] ?? $i->status,
                $i->currency,
            ]));
    }

    public function remind(Request $request, CurrentTenant $currentTenant, Invoice $invoice, NotifyInvoiceGuardians $notifier): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        $this->authorizeFinanceStaff($tenant->id, $request->user());

        $count = $notifier->remind($invoice, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => "შეხსენება გაეგზავნა {$count} მშობელს."]);
    }

    public function remindOverdue(Request $request, CurrentTenant $currentTenant, NotifyInvoiceGuardians $notifier): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());

        $result = $notifier->remindOverdue($tenant->id, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "შეხსენება გაიგზავნა {$result['sent']} ინვოისზე".($result['skipped'] > 0 ? "; {$result['skipped']} გამოტოვდა (ახლახან გაგზავნილი ან ადრესატის გარეშე)." : '.'),
        ]);
    }

    /**
     * @return array{0: int|null, 1: bool}
     */
    private function filters(Request $request, int $tenantId): array
    {
        $classId = $request->integer('class') ?: null;

        if ($classId !== null && ! SchoolClass::query()->where('tenant_id', $tenantId)->whereKey($classId)->exists()) {
            $classId = null;
        }

        return [$classId, $request->boolean('overdue')];
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $value, 'Asia/Tbilisi')?->startOfDay() ?: null;
    }
}
