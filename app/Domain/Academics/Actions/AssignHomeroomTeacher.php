<?php

namespace App\Domain\Academics\Actions;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Sets (or clears) a class's homeroom teacher. The homeroom teacher gains
 * the report card review step for that class, so this is a role-like
 * change and is audit-logged.
 */
class AssignHomeroomTeacher
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(SchoolClass $schoolClass, User $actor, ?int $teacherUserId): void
    {
        if ($teacherUserId !== null
            && ! TenantMembership::userHasActiveRole($schoolClass->tenant_id, $teacherUserId, TenantMembership::ROLE_TEACHER)) {
            throw ValidationException::withMessages([
                'homeroom_teacher_id' => 'არჩეული პირი ამ სკოლის აქტიური მასწავლებელი არ არის.',
            ]);
        }

        $previous = $schoolClass->homeroom_teacher_id;
        $schoolClass->homeroom_teacher_id = $teacherUserId;
        $schoolClass->save();

        $this->auditLogger->record($schoolClass->tenant_id, 'school_class.homeroom_assigned', $schoolClass, $actor->id, [
            'from_user_id' => $previous,
            'to_user_id' => $teacherUserId,
        ]);
    }
}
