<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Actions\AssignSubstitute;
use App\Domain\Timetable\Actions\CancelSubstitutionAssignment;
use App\Domain\Timetable\Actions\RecordStaffAbsence;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\StaffAbsence;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\AssignSubstituteRequest;
use App\Http\Requests\Portal\StoreStaffAbsenceRequest;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "ჩანაცვლებები" — the one piece of the teacher workspace the last audit
 * found missing (lessons/attendance/teacher_assignments were already real).
 * Admin/academic_manager/director report a teacher's absence and assign a
 * substitute per affected lesson+date; the substitute then sees the
 * coverage on their own dashboard for that date (DashboardController).
 * Nav visibility here is not the authorization boundary — every action
 * below re-checks the role itself, same pattern as
 * AcademicStructureController.
 */
class SubstitutionController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ACCESS_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * Longest date range (inclusive) an absence's coverage gaps are
     * computed over — a defensive cap, not a real school-term limit.
     */
    private const MAX_COVERAGE_DAYS = 31;

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeAccess($tenant->id, $request->user()->id);

        $teachers = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', TenantMembership::ROLE_TEACHER)
            ->where('is_active', true)
            ->with('user')
            ->get()
            ->unique('user_id')
            ->map(fn (TenantMembership $membership) => [
                'id' => $membership->user_id,
                'name' => $membership->user->name,
            ])
            ->values();

        $absences = StaffAbsence::query()
            ->where('tenant_id', $tenant->id)
            ->with('teacher')
            ->orderByDesc('starts_on')
            ->get();

        $assignments = SubstitutionAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->with(['lesson.subject', 'lesson.schoolClass', 'absentTeacher', 'substituteTeacher'])
            ->orderByDesc('date')
            ->get();

        return Inertia::render('portal/substitutions/index', [
            'teachers' => $teachers,
            'absences' => $absences->map(fn (StaffAbsence $absence) => [
                'id' => $absence->id,
                'teacherId' => $absence->user_id,
                'teacherName' => $absence->teacher->name,
                'startsOn' => $absence->starts_on->toDateString(),
                'endsOn' => $absence->ends_on->toDateString(),
                'reason' => $absence->reason,
                'status' => $absence->status,
            ])->values(),
            'coverageNeeded' => $this->coverageNeeded($tenant->id, $absences),
            'assignments' => $assignments->map(fn (SubstitutionAssignment $assignment) => [
                'id' => $assignment->id,
                'lessonId' => $assignment->lesson_id,
                'subject' => $assignment->lesson->subject->name,
                'className' => $assignment->lesson->schoolClass->name,
                'date' => $assignment->date->toDateString(),
                'startsAt' => substr($assignment->lesson->starts_at, 0, 5),
                'endsAt' => substr($assignment->lesson->ends_at, 0, 5),
                'absentTeacherName' => $assignment->absentTeacher->name,
                'substituteTeacherName' => $assignment->substituteTeacher->name,
                'status' => $assignment->status,
            ])->values(),
        ]);
    }

    public function storeAbsence(StoreStaffAbsenceRequest $request, CurrentTenant $currentTenant, RecordStaffAbsence $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);

        $teacherId = $request->integer('user_id');
        abort_unless(TenantMembership::userHasActiveRole($tenant->id, $teacherId, TenantMembership::ROLE_TEACHER), 404);

        $teacher = User::query()->find($teacherId);
        abort_if($teacher === null, 404);

        $action->handle(
            tenantId: $tenant->id,
            actor: $actor,
            teacher: $teacher,
            startsOn: Carbon::parse((string) $request->string('starts_on')),
            endsOn: Carbon::parse((string) $request->string('ends_on')),
            reason: $request->string('reason')->toString() ?: null,
        );

        return redirect()->route('substitutions.index')->with('toast', [
            'type' => 'success', 'message' => 'გაცდენა დაფიქსირდა.',
        ]);
    }

    public function assign(AssignSubstituteRequest $request, CurrentTenant $currentTenant, AssignSubstitute $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);

        $lesson = Lesson::query()->where('tenant_id', $tenant->id)->find($request->integer('lesson_id'));
        abort_if($lesson === null, 404);

        $substituteTeacherId = $request->integer('substitute_teacher_id');
        abort_unless(TenantMembership::userHasActiveRole($tenant->id, $substituteTeacherId, TenantMembership::ROLE_TEACHER), 404);

        $substituteTeacher = User::query()->find($substituteTeacherId);
        abort_if($substituteTeacher === null, 404);

        $absence = null;

        if ($request->filled('absence_id')) {
            $absence = StaffAbsence::query()->where('tenant_id', $tenant->id)->find($request->integer('absence_id'));
            abort_if($absence === null, 404);
        }

        $action->handle(
            tenantId: $tenant->id,
            actor: $actor,
            lesson: $lesson,
            substituteTeacher: $substituteTeacher,
            date: Carbon::parse((string) $request->string('date')),
            absence: $absence,
        );

        return redirect()->route('substitutions.index')->with('toast', [
            'type' => 'success', 'message' => 'შემცვლელი დაინიშნა.',
        ]);
    }

    public function cancel(Request $request, CurrentTenant $currentTenant, SubstitutionAssignment $substitutionAssignment, CancelSubstitutionAssignment $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);
        abort_unless($substitutionAssignment->tenant_id === $tenant->id, 404);

        $action->handle($substitutionAssignment, $actor);

        return redirect()->route('substitutions.index')->with('toast', [
            'type' => 'success', 'message' => 'ჩანაცვლება გაუქმდა.',
        ]);
    }

    /**
     * For each reported/covered absence, walks its date range day by day
     * and lists that absent teacher's published lessons on each date that
     * have no active substitution_assignment yet — the admin's actual "who
     * still needs covering" worklist. Capped at MAX_COVERAGE_DAYS per
     * absence; this is a UI worklist, not a scheduling guarantee.
     *
     * @param  Collection<int, StaffAbsence>  $absences
     * @return array<int, array<string, mixed>>
     */
    private function coverageNeeded(int $tenantId, Collection $absences): array
    {
        $rows = [];

        foreach ($absences as $absence) {
            $period = CarbonPeriod::create($absence->starts_on, $absence->ends_on);
            $daysSeen = 0;

            foreach ($period as $date) {
                if (++$daysSeen > self::MAX_COVERAGE_DAYS) {
                    break;
                }

                $lessons = Lesson::query()
                    ->where('tenant_id', $tenantId)
                    ->where('teacher_id', $absence->user_id)
                    ->where('status', Lesson::STATUS_PUBLISHED)
                    ->where('day_of_week', $date->dayOfWeekIso)
                    ->with(['subject', 'schoolClass'])
                    ->orderBy('starts_at')
                    ->get();

                foreach ($lessons as $lesson) {
                    $alreadyCovered = SubstitutionAssignment::query()
                        ->where('tenant_id', $tenantId)
                        ->where('lesson_id', $lesson->id)
                        ->whereDate('date', $date->toDateString())
                        ->where('status', SubstitutionAssignment::STATUS_ASSIGNED)
                        ->exists();

                    if ($alreadyCovered) {
                        continue;
                    }

                    $rows[] = [
                        'lessonId' => $lesson->id,
                        'absenceId' => $absence->id,
                        'date' => $date->toDateString(),
                        'subject' => $lesson->subject->name,
                        'className' => $lesson->schoolClass->name,
                        'startsAt' => substr($lesson->starts_at, 0, 5),
                        'endsAt' => substr($lesson->ends_at, 0, 5),
                        'absentTeacherId' => $absence->user_id,
                        'absentTeacherName' => $absence->teacher->name,
                    ];
                }
            }
        }

        return $rows;
    }

    private function authorizeAccess(int $tenantId, int $userId): void
    {
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::ACCESS_ROLES), 403);
    }
}
