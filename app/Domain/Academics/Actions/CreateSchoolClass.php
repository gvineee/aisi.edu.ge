<?php

namespace App\Domain\Academics\Actions;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Governance\AuditLogger;
use App\Models\User;

/**
 * Creates a `school_classes` row. Before this action existed no route or
 * controller could create one, which was the confirmed production root
 * blocker for the Assignments module: MemberController's invite-a-teacher
 * form reads its class dropdown from `SchoolClass::query()`, and a
 * TeacherAssignment (which every Assignment belongs to) can only be created
 * by accepting a teacher invitation with a school_class_id — so with zero
 * classes the whole chain was unreachable through any real HTTP endpoint.
 */
class CreateSchoolClass
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(int $tenantId, User $actor, AcademicYear $academicYear, string $name): SchoolClass
    {
        $schoolClass = new SchoolClass([
            'academic_year_id' => $academicYear->id,
            'name' => $name,
        ]);
        $schoolClass->tenant_id = $tenantId;
        $schoolClass->save();

        $this->auditLogger->record($tenantId, 'school_class.created', $schoolClass, $actor->id, [
            'name' => $schoolClass->name,
            'academic_year_id' => $academicYear->id,
        ]);

        return $schoolClass;
    }
}
