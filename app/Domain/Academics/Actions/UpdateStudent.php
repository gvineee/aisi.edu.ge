<?php

namespace App\Domain\Academics\Actions;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Models\User;

/**
 * Edits a roster row: name, national id, class transfer and
 * (de)activation. The audit entry records which fields changed and the
 * class move — never the personal values themselves (the national id does
 * not go into the audit log).
 */
class UpdateStudent
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(
        Student $student,
        User $actor,
        SchoolClass $schoolClass,
        string $firstName,
        string $lastName,
        ?string $nationalId,
        bool $isActive,
    ): void {
        $fromClassId = $student->school_class_id;
        $wasActive = $student->is_active;

        $student->fill([
            'school_class_id' => $schoolClass->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'national_id' => $nationalId,
            'is_active' => $isActive,
        ]);

        if (! $student->isDirty()) {
            return;
        }

        $meta = ['changed_fields' => array_keys($student->getDirty())];
        $student->save();

        if ($fromClassId !== $schoolClass->id) {
            $meta['school_class'] = ['from' => $fromClassId, 'to' => $schoolClass->id];
        }

        $action = match (true) {
            $wasActive && ! $isActive => 'student.deactivated',
            ! $wasActive && $isActive => 'student.reactivated',
            $fromClassId !== $schoolClass->id => 'student.transferred',
            default => 'student.updated',
        };

        $this->auditLogger->record($student->tenant_id, $action, $student, $actor->id, $meta);
    }
}
