<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Analytics\AcademicOverview;
use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * „აკადემიური მიმოხილვა“: classes side by side for a year or one
 * assessment period, and one class opened up by subject and pupil.
 * Management only — it shows pupils' attendance and results.
 */
class AcademicOverviewController extends Controller
{
    /** @var list<string> */
    public const ACCESS_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function __invoke(Request $request, CurrentTenant $currentTenant, AcademicOverview $overview): Response
    {
        $tenant = $currentTenant->get();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::ACCESS_ROLES), 403);
        $today = Carbon::now($tenant->timezone)->startOfDay();

        $years = AcademicYear::query()->where('tenant_id', $tenant->id)->orderByDesc('starts_on')->get();
        $year = $years->firstWhere('id', $request->integer('year')) ?? $years->firstWhere('is_current', true) ?? $years->first();
        $periods = $year === null ? collect() : AssessmentPeriod::query()->where('tenant_id', $tenant->id)->where('academic_year_id', $year->id)->orderBy('starts_on')->get();
        $period = $periods->firstWhere('id', $request->integer('period'));
        $classes = $year === null ? collect() : SchoolClass::query()->where('tenant_id', $tenant->id)->where('academic_year_id', $year->id)->get();
        $class = $classes->firstWhere('id', $request->integer('class'));

        // The period's dates, or the year's up to today.
        $from = Carbon::parse(($period ?? $year)?->starts_on?->toDateString() ?? $today->toDateString());
        $end = Carbon::parse(($period ?? $year)?->ends_on?->toDateString() ?? $today->toDateString());
        $to = $end->greaterThan($today) ? $today->copy() : $end;
        $periodIds = $period !== null ? [$period->id] : array_values(array_map('intval', $periods->pluck('id')->all()));

        return Inertia::render('portal/academic-overview/index', [
            'years' => $years->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name])->values(),
            'yearId' => $year?->id,
            'periods' => $periods->map(fn (AssessmentPeriod $p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'periodId' => $period?->id,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'computedAt' => now()->toIso8601String(),
            'classes' => $year === null ? [] : $overview->classes($tenant->id, $classes, $periodIds, $from, $to, $today),
            'selected' => $class === null ? null : ['id' => $class->id, 'name' => $class->name] + $overview->classDetail($tenant->id, $class, $periodIds, $from, $to, $today),
        ]);
    }
}
