<?php

namespace App\Domain\Communications\Actions;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The one place that answers "who is this user allowed to start a new
 * conversation with" — a real relationship check (docs/02's guardian/
 * teacher-assignment invariants), never just "any user in the tenant".
 *
 * Rules:
 * - Any staff member (teacher/academic_manager/accountant/editor/director/
 *   admin) can reach any other staff member — internal school comms.
 * - A teacher can reach guardians of active students in their assigned
 *   classes only, never the whole school's families.
 * - A guardian or a logged-in student can reach the teacher(s) of their own
 *   (linked, active) class, plus school leadership (office roles below) —
 *   never an unrelated class's teacher, never another family.
 * - "Office" roles (admin/director/academic_manager/accountant/editor) are
 *   reachable by, and can reach, any guardian in the tenant — school
 *   leadership needs to be reachable without a specific class link.
 */
class ResolveMessageableUsers
{
    /**
     * @var array<int, string>
     */
    private const STAFF_ROLES = [
        TenantMembership::ROLE_TEACHER,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ACCOUNTANT,
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * Staff roles NOT tied to a specific class — reachable by/can reach any
     * guardian in the tenant, unlike a teacher whose reach is class-scoped.
     *
     * @var array<int, string>
     */
    private const OFFICE_ROLES = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ACCOUNTANT,
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * @return Collection<int, User>
     */
    public function forUser(int $tenantId, int $userId): Collection
    {
        $roles = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('role');

        $isTeacher = $roles->contains(TenantMembership::ROLE_TEACHER);
        $isOffice = $roles->intersect(self::OFFICE_ROLES)->isNotEmpty();
        $isGuardian = $roles->contains(TenantMembership::ROLE_GUARDIAN);
        $isStudent = $roles->contains(TenantMembership::ROLE_STUDENT);

        $reachableIds = collect();

        if ($isTeacher || $isOffice) {
            $reachableIds = $reachableIds->merge($this->allStaffIds($tenantId, $userId));
        }

        if ($isTeacher) {
            $reachableIds = $reachableIds->merge($this->guardiansOfClasses($tenantId, $this->teacherClassIds($tenantId, $userId)));
        }

        if ($isOffice) {
            $reachableIds = $reachableIds->merge($this->allGuardianIds($tenantId));
        }

        if ($isGuardian) {
            $classIds = $this->guardianClassIds($tenantId, $userId);
            $reachableIds = $reachableIds
                ->merge($this->teachersOfClasses($tenantId, $classIds))
                ->merge($this->officeIds($tenantId));
        }

        if ($isStudent) {
            $classId = Student::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->value('school_class_id');

            if ($classId !== null) {
                $reachableIds = $reachableIds
                    ->merge($this->teachersOfClasses($tenantId, collect([$classId])))
                    ->merge($this->officeIds($tenantId));
            }
        }

        $reachableIds = $reachableIds->unique()->reject(fn (int $id) => $id === $userId)->values();

        return User::query()->whereIn('id', $reachableIds)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, int>
     */
    private function allStaffIds(int $tenantId, int $excludingUserId): Collection
    {
        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('role', self::STAFF_ROLES)
            ->where('user_id', '!=', $excludingUserId)
            ->pluck('user_id')
            ->unique();
    }

    /**
     * @return Collection<int, int>
     */
    private function officeIds(int $tenantId): Collection
    {
        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('role', self::OFFICE_ROLES)
            ->pluck('user_id')
            ->unique();
    }

    /**
     * @return Collection<int, int>
     */
    private function allGuardianIds(int $tenantId): Collection
    {
        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('role', TenantMembership::ROLE_GUARDIAN)
            ->pluck('user_id')
            ->unique();
    }

    /**
     * @return Collection<int, int>
     */
    private function teacherClassIds(int $tenantId, int $teacherUserId): Collection
    {
        return TeacherAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $teacherUserId)
            ->pluck('school_class_id')
            ->unique();
    }

    /**
     * @return Collection<int, int>
     */
    private function guardianClassIds(int $tenantId, int $guardianUserId): Collection
    {
        return GuardianLink::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $guardianUserId)
            ->where('is_active', true)
            ->whereHas('student', fn ($query) => $query->where('is_active', true))
            ->with('student')
            ->get()
            ->pluck('student.school_class_id')
            ->filter()
            ->unique();
    }

    /**
     * @param  Collection<int, int>  $classIds
     * @return Collection<int, int>
     */
    private function guardiansOfClasses(int $tenantId, Collection $classIds): Collection
    {
        if ($classIds->isEmpty()) {
            return collect();
        }

        return GuardianLink::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereHas('student', fn ($query) => $query->where('is_active', true)->whereIn('school_class_id', $classIds))
            ->pluck('user_id')
            ->unique();
    }

    /**
     * @param  Collection<int, int>  $classIds
     * @return Collection<int, int>
     */
    private function teachersOfClasses(int $tenantId, Collection $classIds): Collection
    {
        if ($classIds->isEmpty()) {
            return collect();
        }

        return TeacherAssignment::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('school_class_id', $classIds)
            ->pluck('user_id')
            ->unique();
    }
}
