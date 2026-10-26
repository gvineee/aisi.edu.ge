<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Actions\GenerateTimetable;
use App\Domain\Timetable\Models\BellPeriod;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Timetable generation: shows whether a year's workloads fit its week
 * (hours per class against the bell periods), then builds a draft version
 * with GenerateTimetable and opens it in the grid view.
 */
class TimetableGeneratorController extends Controller
{
    /** @var list<string> */
    private const ACCESS_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenantId = $this->authorizeTenant($request, $currentTenant);
        $years = AcademicYear::query()->where('tenant_id', $tenantId)->orderByDesc('starts_on')->get();
        $year = $years->firstWhere('id', $request->integer('year')) ?? $years->firstWhere('is_current', true) ?? $years->first();
        $periods = $year === null ? 0 : BellPeriod::query()->where('tenant_id', $tenantId)->where('academic_year_id', $year->id)->count();

        $workloads = $year === null ? collect() : TeacherAssignment::query()->where('tenant_id', $tenantId)
            ->whereNotNull('staff_member_id')->where('weekly_hours', '>', 0)
            ->whereHas('schoolClass', fn ($q) => $q->where('academic_year_id', $year->id))->get();
        $classes = $year === null ? collect() : SchoolClass::query()->where('tenant_id', $tenantId)->where('academic_year_id', $year->id)->get()->sortBy('name', SORT_NATURAL);

        return Inertia::render('portal/timetable/generator', [
            'years' => $years->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'startsOn' => $y->starts_on->toDateString(), 'endsOn' => $y->ends_on->toDateString()])->values(),
            'yearId' => $year?->id,
            'periods' => $periods,
            'units' => $workloads->unique(fn (TeacherAssignment $a) => $a->joint_key ?? "a{$a->id}")->count(),
            'hours' => (int) $workloads->unique(fn (TeacherAssignment $a) => $a->joint_key ?? "a{$a->id}")->sum('weekly_hours'),
            'unassigned' => $year === null ? 0 : TeacherAssignment::query()->where('tenant_id', $tenantId)->whereNull('staff_member_id')
                ->whereHas('schoolClass', fn ($q) => $q->where('academic_year_id', $year->id))->count(),
            // Whole-class hours a class must sit through; groups may share slots.
            'classes' => $classes->map(fn (SchoolClass $c) => [
                'name' => $c->name,
                'hours' => (int) $workloads->where('school_class_id', $c->id)->whereNull('student_group_id')->sum('weekly_hours'),
                'groupHours' => (int) $workloads->where('school_class_id', $c->id)->whereNotNull('student_group_id')->sum('weekly_hours'),
                'capacity' => $periods * 5,
            ])->values(),
            'result' => $request->session()->get('generation'),
        ]);
    }

    public function store(Request $request, CurrentTenant $currentTenant, GenerateTimetable $generator): RedirectResponse
    {
        $tenantId = $this->authorizeTenant($request, $currentTenant);
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:120'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'periods_per_day' => ['nullable', 'integer', 'min:1', 'max:15'],
        ]);
        $year = AcademicYear::query()->where('tenant_id', $tenantId)->findOrFail((int) $data['academic_year_id']);

        if ($data['valid_from'] < $year->starts_on->toDateString() || ($data['valid_to'] ?? $data['valid_from']) > $year->ends_on->toDateString()) {
            return back()->withErrors(['valid_from' => 'თარიღები სასწავლო წლის ფარგლებში უნდა იყოს.']);
        }

        try {
            $result = $generator->handle($year, $request->user(), trim($data['label']), $data['valid_from'], $data['valid_to'] ?? null, isset($data['periods_per_day']) ? (int) $data['periods_per_day'] : null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['academic_year_id' => $exception->getMessage()]);
        }

        $message = $result['unplaced'] === []
            ? "ცხრილი შეიქმნა მონახაზად: {$result['placed']} გაკვეთილი, ყველა განთავსდა."
            : "ცხრილი შეიქმნა მონახაზად: {$result['placed']} გაკვეთილი. ".array_sum(array_column($result['unplaced'], 'missing')).' ვერ განთავსდა — სია გენერატორის გვერდზეა.';

        return redirect()->route('timetable.generator', ['year' => $year->id])
            ->with('generation', ['versionId' => $result['version']->id, 'placed' => $result['placed'], 'unplaced' => $result['unplaced']])
            ->with('toast', ['type' => $result['unplaced'] === [] ? 'success' : 'warning', 'message' => $message]);
    }

    private function authorizeTenant(Request $request, CurrentTenant $currentTenant): int
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, self::ACCESS_ROLES), 403);

        return $tenantId;
    }
}
