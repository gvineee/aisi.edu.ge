<?php

namespace App\Domain\Learning;

use App\Domain\Academics\Models\ClassStaffRole;
use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may write and who may review absence notes. A guardian writes only
 * for a child with an active guardian link that allows academic access.
 * Reviewers are the school administration (all pupils) and a class's
 * homeroom / co-homeroom teachers (their own class only).
 */
class AbsenceNoteAccess
{
    /** @var list<string> */
    public const ADMINISTRATION_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function guardianMayWrite(int $tenantId, int $userId, int $studentId): bool
    {
        return TenantMembership::userHasActiveRole($tenantId, $userId, TenantMembership::ROLE_GUARDIAN)
            && GuardianLink::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('student_id', $studentId)
                ->where('is_active', true)
                ->where('can_view_academic', true)
                ->exists();
    }

    public function isReviewer(int $tenantId, int $userId): bool
    {
        return TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::ADMINISTRATION_ROLES)
            || $this->homeroomClassIds($tenantId, $userId) !== [];
    }

    public function mayReview(int $tenantId, int $userId, Student $student): bool
    {
        if (TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::ADMINISTRATION_ROLES)) {
            return true;
        }

        return in_array($student->school_class_id, $this->homeroomClassIds($tenantId, $userId), true);
    }

    /**
     * Pupils whose notes this reviewer sees.
     *
     * @return Builder<Student>
     */
    public function reviewableStudents(int $tenantId, int $userId): Builder
    {
        $query = Student::query()->where('tenant_id', $tenantId);

        if (TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::ADMINISTRATION_ROLES)) {
            return $query;
        }

        return $query->whereIn('school_class_id', $this->homeroomClassIds($tenantId, $userId));
    }

    /**
     * @return list<int>
     */
    private function homeroomClassIds(int $tenantId, int $userId): array
    {
        if (! TenantMembership::userHasActiveRole($tenantId, $userId, TenantMembership::ROLE_TEACHER)) {
            return [];
        }

        return array_values(SchoolClass::query()
            ->where('tenant_id', $tenantId)
            ->where('homeroom_teacher_id', $userId)
            ->pluck('id')
            ->merge(ClassStaffRole::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('role', ClassStaffRole::ROLE_CO_HOMEROOM)
                ->pluck('school_class_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all());
    }
}
