<?php

namespace App\Domain\Learning;

use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Learning\Models\CurriculumPlan;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\Lesson;

/**
 * Who may see and change curriculum plans. Management sees and edits every
 * plan and approves them; a teacher sees all plans (they are not personal
 * data) and drafts the plans of the class/subject pairs they teach — by
 * workload or by timetabled lesson. An approved plan is management's.
 */
class CurriculumAccess
{
    /** @var list<string> */
    public const MANAGE_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function isManager(int $tenantId, int $userId): bool
    {
        return TenantMembership::userHasAnyActiveRole($tenantId, $userId, self::MANAGE_ROLES);
    }

    public function canView(int $tenantId, int $userId): bool
    {
        return TenantMembership::userHasAnyActiveRole($tenantId, $userId, [...self::MANAGE_ROLES, TenantMembership::ROLE_TEACHER]);
    }

    public function teaches(int $tenantId, int $userId, int $classId, int $subjectId): bool
    {
        if (! TenantMembership::userHasActiveRole($tenantId, $userId, TenantMembership::ROLE_TEACHER)) {
            return false;
        }

        return TeacherAssignment::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('school_class_id', $classId)->where('subject_id', $subjectId)->exists()
            || Lesson::query()->where('tenant_id', $tenantId)->where('teacher_id', $userId)
                ->where('school_class_id', $classId)->where('subject_id', $subjectId)->exists();
    }

    public function canEdit(CurriculumPlan $plan, int $userId): bool
    {
        return $this->isManager($plan->tenant_id, $userId)
            || ($plan->status === CurriculumPlan::STATUS_DRAFT && $this->teaches($plan->tenant_id, $userId, $plan->school_class_id, $plan->subject_id));
    }
}
