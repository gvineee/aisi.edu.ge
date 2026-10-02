<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Assessment\Actions\ChangeAssessmentPeriodStatus;
use App\Domain\Assessment\Actions\CreateAssessmentPeriod;
use App\Domain\Assessment\Actions\CreateGradeScale;
use App\Domain\Assessment\Models\AssessmentCategory;
use App\Domain\Assessment\Models\AssessmentPeriod;
use App\Domain\Assessment\Models\GradeScale;
use App\Domain\Assessment\Models\GradeScaleBand;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\ReopenAssessmentPeriodRequest;
use App\Http\Requests\Portal\StoreAssessmentCategoryRequest;
use App\Http\Requests\Portal\StoreAssessmentPeriodRequest;
use App\Http\Requests\Portal\StoreGradeScaleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "შეფასების წესები" — where the school's own grading setup lives:
 * assessment periods (open/close/reopen), grade scale versions and
 * assessment categories. No scale or category is seeded: those are the
 * school's approved grading policy, not something the platform invents.
 */
class AssessmentSettingsController extends Controller
{
    /**
     * @var array<int, string>
     */
    public const ACCESS_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request->user()->id);

        $academicYears = AcademicYear::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('starts_on')
            ->get();

        $periods = AssessmentPeriod::query()
            ->where('tenant_id', $tenant->id)
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->get();

        $scales = GradeScale::query()
            ->where('tenant_id', $tenant->id)
            ->with('bands')
            ->orderByDesc('version')
            ->get();

        $categories = AssessmentCategory::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();

        return Inertia::render('portal/assessment-settings/index', [
            'academicYears' => $academicYears->map(fn (AcademicYear $year) => [
                'id' => $year->id,
                'name' => $year->name,
                'startsOn' => $year->starts_on->toDateString(),
                'endsOn' => $year->ends_on->toDateString(),
            ])->values(),
            'periods' => $periods->map(fn (AssessmentPeriod $period) => [
                'id' => $period->id,
                'name' => $period->name,
                'academicYearName' => $period->academicYear->name ?? null,
                'startsOn' => $period->starts_on->toDateString(),
                'endsOn' => $period->ends_on->toDateString(),
                'status' => $period->status,
                'closedAt' => $period->closed_at?->toIso8601String(),
            ])->values(),
            'scales' => $scales->map(fn (GradeScale $scale) => [
                'id' => $scale->id,
                'name' => $scale->name,
                'version' => $scale->version,
                'isActive' => $scale->is_active,
                'bands' => $scale->bands->map(fn (GradeScaleBand $band) => [
                    'minPercent' => $band->min_percent,
                    'label' => $band->label,
                ])->values(),
            ])->values(),
            'categories' => $categories->map(fn (AssessmentCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])->values(),
        ]);
    }

    public function storePeriod(StoreAssessmentPeriodRequest $request, CurrentTenant $currentTenant, CreateAssessmentPeriod $createAssessmentPeriod): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request->user()->id);

        $academicYear = AcademicYear::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($request->integer('academic_year_id'))
            ->firstOrFail();

        $createAssessmentPeriod->handle(
            academicYear: $academicYear,
            actor: $request->user(),
            name: $request->string('name')->toString(),
            startsOn: Carbon::parse((string) $request->string('starts_on')),
            endsOn: Carbon::parse((string) $request->string('ends_on')),
        );

        return back();
    }

    public function closePeriod(Request $request, CurrentTenant $currentTenant, AssessmentPeriod $assessmentPeriod, ChangeAssessmentPeriodStatus $changeStatus): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($assessmentPeriod->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request->user()->id);

        $changeStatus->close($assessmentPeriod, $request->user());

        return back();
    }

    public function reopenPeriod(ReopenAssessmentPeriodRequest $request, CurrentTenant $currentTenant, AssessmentPeriod $assessmentPeriod, ChangeAssessmentPeriodStatus $changeStatus): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($assessmentPeriod->tenant_id === $tenant->id, 404);
        $this->authorizeAccess($tenant->id, $request->user()->id);

        $changeStatus->reopen($assessmentPeriod, $request->user(), $request->string('reason')->toString());

        return back();
    }

    public function storeScale(StoreGradeScaleRequest $request, CurrentTenant $currentTenant, CreateGradeScale $createGradeScale): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request->user()->id);

        /** @var array<int, array{min_percent: string, label: string}> $bands */
        $bands = array_values($request->array('bands'));

        $createGradeScale->handle($tenant->id, $request->user(), $request->string('name')->toString(), $bands);

        return back();
    }

    public function storeCategory(StoreAssessmentCategoryRequest $request, CurrentTenant $currentTenant): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request->user()->id);

        $category = new AssessmentCategory(['name' => $request->string('name')->toString()]);
        $category->tenant_id = $tenant->id;
        $category->save();

        return back();
    }

    private function authorizeAccess(int $tenantId, int $userId): void
    {
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::ACCESS_ROLES), 403);
    }
}
