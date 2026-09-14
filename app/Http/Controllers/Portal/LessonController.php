<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Actions\CreateLesson;
use App\Domain\Timetable\Actions\CreateSubject;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\Room;
use App\Domain\Timetable\Models\Subject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreLessonRequest;
use App\Http\Requests\Portal\StoreSubjectRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Schedule management — only an academic manager or admin may create or
 * change lessons (docs/02 permissions matrix: "განრიგი: ... სკოლის
 * მართვა" for the manager role, not the teacher who only sees their own).
 *
 * Before `index()`/`storeSubject()` existed here, this controller's own
 * `store()` was unreachable dead code from any real UI: there was no page
 * anywhere that posted to it, and no route/controller anywhere could create
 * a Subject (`subject_id` is required to create a Lesson). Production
 * verification of the Teacher Substitution module confirmed this as the
 * root blocker — zero subjects/lessons meant the absence/coverage worklist
 * could never be populated through any real HTTP endpoint, only by hand in
 * tinker/seeders. This screen (`portal/timetable`) closes that gap the same
 * way AcademicStructureController closed it for academic_years/
 * school_classes/students.
 */
class LessonController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ACCESS_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
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

        $schoolClasses = SchoolClass::query()
            ->where('tenant_id', $tenant->id)
            ->with('academicYear')
            ->orderBy('name')
            ->get();

        $subjects = Subject::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();

        $rooms = Room::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();

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

        $lessons = Lesson::query()
            ->where('tenant_id', $tenant->id)
            ->with(['academicYear', 'schoolClass', 'subject', 'teacher', 'room'])
            ->orderByDesc('day_of_week')
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('portal/timetable/index', [
            'academicYears' => $academicYears->map(fn (AcademicYear $year) => [
                'id' => $year->id,
                'name' => $year->name,
                'isCurrent' => $year->is_current,
            ])->values(),
            'schoolClasses' => $schoolClasses->map(fn (SchoolClass $schoolClass) => [
                'id' => $schoolClass->id,
                'name' => $schoolClass->name,
                'academicYearId' => $schoolClass->academic_year_id,
                'academicYearName' => $schoolClass->academicYear->name,
            ])->values(),
            'subjects' => $subjects->map(fn (Subject $subject) => [
                'id' => $subject->id,
                'name' => $subject->name,
            ])->values(),
            'rooms' => $rooms->map(fn (Room $room) => [
                'id' => $room->id,
                'name' => $room->name,
            ])->values(),
            'teachers' => $teachers,
            'lessons' => $lessons->map(fn (Lesson $lesson) => [
                'id' => $lesson->id,
                'schoolClassName' => $lesson->schoolClass->name,
                'subjectName' => $lesson->subject->name,
                'teacherName' => $lesson->teacher->name,
                'roomName' => $lesson->room?->name,
                'dayOfWeek' => $lesson->day_of_week,
                'startsAt' => substr($lesson->starts_at, 0, 5),
                'endsAt' => substr($lesson->ends_at, 0, 5),
                'status' => $lesson->status,
            ])->values(),
        ]);
    }

    public function storeSubject(StoreSubjectRequest $request, CurrentTenant $currentTenant, CreateSubject $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);

        $action->handle($tenant->id, $actor, $request->string('name')->toString());

        return back()->with('toast', [
            'type' => 'success', 'message' => 'საგანი დაემატა.',
        ]);
    }

    public function store(StoreLessonRequest $request, CurrentTenant $currentTenant, CreateLesson $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $actor = $request->user();
        $this->authorizeAccess($tenant->id, $actor->id);

        $data = $request->validated();
        $data['starts_at'] .= ':00';
        $data['ends_at'] .= ':00';

        $lesson = $action->handle($tenant->id, $actor, $data);

        return back()->with('lessonId', $lesson->id)->with('toast', [
            'type' => 'success', 'message' => 'გაკვეთილი დაემატა.',
        ]);
    }

    private function authorizeAccess(int $tenantId, int $userId): void
    {
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::ACCESS_ROLES), 403);
    }
}
