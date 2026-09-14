<?php

namespace App\Domain\Academics\Actions;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Models\User;

/**
 * Creates a `students` row on a real class roster. Like CreateAcademicYear/
 * CreateSchoolClass, this previously had no HTTP-reachable writer at all
 * (only TenantSeeder) — which meant that even after a class existed, an
 * admin still had no way to add a real student for a guardian invite,
 * self-service enrollment verification, or an Assignment submission to
 * attach to. national_id is optional (a school may not have collected it
 * yet) but must be unique per tenant when set — enforced by the DB
 * constraint; a duplicate surfaces as a validation error, not a 500.
 */
class CreateStudent
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(
        int $tenantId,
        User $actor,
        SchoolClass $schoolClass,
        string $firstName,
        string $lastName,
        ?string $nationalId,
    ): Student {
        $student = new Student([
            'school_class_id' => $schoolClass->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'national_id' => $nationalId,
            'is_active' => true,
        ]);
        $student->tenant_id = $tenantId;
        $student->save();

        $this->auditLogger->record($tenantId, 'student.created', $student, $actor->id, [
            'name' => $student->fullName(),
            'school_class_id' => $schoolClass->id,
        ]);

        return $student;
    }
}
