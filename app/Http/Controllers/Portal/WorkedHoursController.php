<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Actions\CalculateWorkedHours;
use App\Domain\Timetable\Models\NonTeachingPeriod;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Worked hours for the accountant: lessons each teacher actually taught in
 * a month (or any range up to a year), after cancellations and cover —
 * CalculateWorkedHours — with a CSV for payroll. Holidays and vacations are
 * kept here too, since they are what makes a month's plan.
 */
class WorkedHoursController extends Controller
{
    /** @var list<string> */
    private const VIEW_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ACCOUNTANT,
    ];

    /** @var list<string> */
    private const CALENDAR_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function index(Request $request, CurrentTenant $currentTenant, CalculateWorkedHours $hours): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeRoles($request, $tenant->id, self::VIEW_ROLES);
        [$from, $to] = $this->range($request, $tenant->timezone);

        return Inertia::render('portal/worked-hours/index', [
            'report' => $hours->handle($tenant->id, $from, $to),
            'canManageCalendar' => TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::CALENDAR_ROLES),
            'years' => AcademicYear::query()->where('tenant_id', $tenant->id)->orderByDesc('starts_on')->get()
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'startsOn' => $y->starts_on->toDateString(), 'endsOn' => $y->ends_on->toDateString()])->values(),
            'breaks' => NonTeachingPeriod::query()->where('tenant_id', $tenant->id)->orderBy('starts_on')->get()
                ->map(fn (NonTeachingPeriod $b) => ['id' => $b->id, 'academicYearId' => $b->academic_year_id, 'label' => $b->label, 'startsOn' => $b->starts_on->toDateString(), 'endsOn' => $b->ends_on->toDateString()])->values(),
        ]);
    }

    public function export(Request $request, CurrentTenant $currentTenant, CalculateWorkedHours $hours, AuditLogger $auditLogger): StreamedResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeRoles($request, $tenant->id, self::VIEW_ROLES);
        [$from, $to] = $this->range($request, $tenant->timezone);
        $report = $hours->handle($tenant->id, $from, $to);

        $auditLogger->record($tenant->id, 'worked_hours.exported', $tenant, $request->user()->id, [
            'from' => $report['from'], 'to' => $report['to'], 'rows' => count($report['rows']),
        ]);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 Georgian only with a BOM
            fputcsv($out, ['თანამშრომელი', 'დაგეგმილი', 'გაუქმებული', 'სხვამ ჩაატარა', 'ჩაანაცვლა', 'ჩატარებული გაკვეთილი', 'ჩატარებული წუთი', 'საათი (60 წთ)', 'პერიოდი']);

            foreach ($report['rows'] as $row) {
                fputcsv($out, [
                    $row['name'], $row['planned'], $row['cancelled'], $row['handedOver'], $row['covered'], $row['taught'],
                    $row['minutes'], number_format($row['minutes'] / 60, 2, '.', ''), "{$report['from']} – {$report['to']}",
                ]);
            }

            fclose($out);
        }, "worked-hours-{$report['from']}-{$report['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function storeBreak(Request $request, CurrentTenant $currentTenant, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        $this->authorizeRoles($request, $tenantId, self::CALENDAR_ROLES);
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
        $year = AcademicYear::query()->where('tenant_id', $tenantId)->findOrFail((int) $data['academic_year_id']);

        if ($data['starts_on'] < $year->starts_on->toDateString() || $data['ends_on'] > $year->ends_on->toDateString()) {
            return back()->withErrors(['starts_on' => 'თარიღები სასწავლო წლის ფარგლებში უნდა იყოს.']);
        }

        $break = new NonTeachingPeriod(['academic_year_id' => $year->id, 'label' => trim($data['label']), 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'created_by' => $request->user()->id]);
        $break->tenant_id = $tenantId;
        $break->save();
        $auditLogger->record($tenantId, 'calendar.break_added', $break, $request->user()->id);

        return back()->with('toast', ['type' => 'success', 'message' => 'არასასწავლო დღეები დაემატა.']);
    }

    public function destroyBreak(Request $request, CurrentTenant $currentTenant, NonTeachingPeriod $nonTeachingPeriod, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($nonTeachingPeriod->tenant_id === $tenantId, 404);
        $this->authorizeRoles($request, $tenantId, self::CALENDAR_ROLES);
        $auditLogger->record($tenantId, 'calendar.break_removed', $nonTeachingPeriod, $request->user()->id, ['label' => $nonTeachingPeriod->label]);
        $nonTeachingPeriod->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'წაიშალა.']);
    }

    /**
     * `month=Y-m`, or `from`/`to` (at most a year apart); default this month.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request, string $timezone): array
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if (! empty($data['from']) && ! empty($data['to'])) {
            $from = Carbon::parse($data['from']);
            $to = Carbon::parse($data['to']);

            abort_if($from->diffInDays($to) > 366, 422);

            return [$from, $to];
        }

        $month = ! empty($data['month']) ? Carbon::createFromFormat('Y-m-d', $data['month'].'-01') : Carbon::now($timezone)->startOfMonth();

        return [$month->copy()->startOfMonth()->startOfDay(), $month->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * @param  list<string>  $roles
     */
    private function authorizeRoles(Request $request, int $tenantId, array $roles): void
    {
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, $roles), 403);
    }
}
