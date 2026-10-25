<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Actions\SaveStudentGroup;
use App\Domain\Academics\Actions\UpdateClassProfile;
use App\Domain\Academics\Models\ClassStaffRole;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\StudentEnrollment;
use App\Domain\Academics\Models\StudentGroup;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;
use App\Domain\Timetable\Models\Room;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One class's profile for the people who run the school structure: grade
 * level, short name, home room, co-homeroom teachers, teaching groups with
 * their members, and the class's enrollment history.
 */
class SchoolClassProfileController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ACCESS_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function show(Request $request, CurrentTenant $currentTenant, SchoolClass $schoolClass): Response
    {
        $tenantId = $this->authorizeClass($request, $currentTenant, $schoolClass);
        $schoolClass->load('academicYear', 'homeroomTeacher');

        $students = Student::query()
            ->where('tenant_id', $tenantId)
            ->where('school_class_id', $schoolClass->id)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $groups = StudentGroup::query()
            ->where('tenant_id', $tenantId)
            ->where('school_class_id', $schoolClass->id)
            ->with('students:id')
            ->orderBy('name')
            ->get();

        $teachers = User::query()
            ->whereIn('id', TenantMembership::query()
                ->where('tenant_id', $tenantId)
                ->where('role', TenantMembership::ROLE_TEACHER)
                ->where('is_active', true)
                ->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $enrollments = StudentEnrollment::query()
            ->where('tenant_id', $tenantId)
            ->where('school_class_id', $schoolClass->id)
            ->with('student:id,first_name,last_name')
            ->orderByDesc('starts_on')
            ->limit(100)
            ->get();

        return Inertia::render('portal/academic-structure/class', [
            'schoolClass' => [
                'id' => $schoolClass->id,
                'name' => $schoolClass->name,
                'academicYearName' => $schoolClass->academicYear->name,
                'gradeLevel' => $schoolClass->grade_level,
                'shortName' => $schoolClass->short_name,
                'homeRoomId' => $schoolClass->home_room_id,
                'homeroomTeacherName' => $schoolClass->homeroomTeacher?->name,
                'coHomeroomIds' => ClassStaffRole::query()
                    ->where('tenant_id', $tenantId)
                    ->where('school_class_id', $schoolClass->id)
                    ->where('role', ClassStaffRole::ROLE_CO_HOMEROOM)
                    ->pluck('user_id')
                    ->values(),
            ],
            'students' => $students->map(fn (Student $student) => ['id' => $student->id, 'name' => $student->fullName()])->values(),
            'groups' => $groups->map(fn (StudentGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'studentIds' => $group->students->pluck('id')->values(),
            ])->values(),
            'teachers' => $teachers->map(fn (User $teacher) => ['id' => $teacher->id, 'name' => $teacher->name])->values(),
            'rooms' => Room::query()->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']),
            'enrollments' => $enrollments->map(fn (StudentEnrollment $enrollment) => [
                'id' => $enrollment->id,
                'studentName' => $enrollment->student->fullName(),
                'startsOn' => $enrollment->starts_on->toDateString(),
                'endsOn' => $enrollment->ends_on?->toDateString(),
                'status' => $enrollment->status,
            ])->values(),
        ]);
    }

    public function update(Request $request, CurrentTenant $currentTenant, SchoolClass $schoolClass, UpdateClassProfile $action): RedirectResponse
    {
        $this->authorizeClass($request, $currentTenant, $schoolClass);

        $data = $request->validate([
            'grade_level' => ['nullable', 'integer', 'between:0,13'],
            'short_name' => ['nullable', 'string', 'max:32'],
            'home_room_id' => ['nullable', 'integer'],
            'co_homeroom_ids' => ['array', 'max:3'],
            'co_homeroom_ids.*' => ['integer'],
        ]);

        $action->handle(
            $schoolClass,
            $request->user(),
            isset($data['grade_level']) ? (int) $data['grade_level'] : null,
            ($data['short_name'] ?? '') !== '' ? $data['short_name'] : null,
            isset($data['home_room_id']) ? (int) $data['home_room_id'] : null,
            array_values(array_map('intval', (array) ($data['co_homeroom_ids'] ?? []))),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'კლასის მონაცემები შენახულია.']);
    }

    public function storeGroup(Request $request, CurrentTenant $currentTenant, SchoolClass $schoolClass, SaveStudentGroup $action): RedirectResponse
    {
        $this->authorizeClass($request, $currentTenant, $schoolClass);
        $data = $this->validateGroup($request);

        $action->handle($schoolClass, $request->user(), null, $data['name'], $data['student_ids']);

        return back()->with('toast', ['type' => 'success', 'message' => 'ჯგუფი დაემატა.']);
    }

    public function updateGroup(Request $request, CurrentTenant $currentTenant, SchoolClass $schoolClass, StudentGroup $studentGroup, SaveStudentGroup $action): RedirectResponse
    {
        $this->authorizeClass($request, $currentTenant, $schoolClass);
        abort_unless($studentGroup->tenant_id === $schoolClass->tenant_id && $studentGroup->school_class_id === $schoolClass->id, 404);
        $data = $this->validateGroup($request);

        $action->handle($schoolClass, $request->user(), $studentGroup, $data['name'], $data['student_ids']);

        return back()->with('toast', ['type' => 'success', 'message' => 'ჯგუფი შენახულია.']);
    }

    public function destroyGroup(Request $request, CurrentTenant $currentTenant, SchoolClass $schoolClass, StudentGroup $studentGroup, AuditLogger $auditLogger): RedirectResponse
    {
        $tenantId = $this->authorizeClass($request, $currentTenant, $schoolClass);
        abort_unless($studentGroup->tenant_id === $tenantId && $studentGroup->school_class_id === $schoolClass->id, 404);

        // Deleting a group that lessons or teaching assignments still use
        // would silently turn them into whole-class ones.
        $inUse = Lesson::query()->where('tenant_id', $tenantId)->where('student_group_id', $studentGroup->id)->exists()
            || TeacherAssignment::query()->where('tenant_id', $tenantId)->where('student_group_id', $studentGroup->id)->exists();

        if ($inUse) {
            return back()->withErrors(['group' => 'ჯგუფს გაკვეთილები ან მასწავლებლის მინიჭება აქვს — ჯერ ისინი შეცვალეთ.']);
        }

        $auditLogger->record($tenantId, 'student_group.deleted', $studentGroup, $request->user()->id, ['school_class_id' => $schoolClass->id]);
        $studentGroup->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'ჯგუფი წაიშალა.']);
    }

    /**
     * @return array{name: string, student_ids: list<int>}
     */
    private function validateGroup(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'student_ids' => ['array'],
            'student_ids.*' => ['integer'],
        ]);

        return [
            'name' => trim((string) $data['name']),
            'student_ids' => array_values(array_map('intval', (array) ($data['student_ids'] ?? []))),
        ];
    }

    private function authorizeClass(Request $request, CurrentTenant $currentTenant, SchoolClass $schoolClass): int
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless($schoolClass->tenant_id === $tenantId, 404);
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, self::ACCESS_ROLES), 403);

        return $tenantId;
    }
}
