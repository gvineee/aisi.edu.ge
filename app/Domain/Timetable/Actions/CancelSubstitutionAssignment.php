<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Timetable\Models\SubstitutionAssignment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Withdraws a substitute assignment (e.g. it was booked in error, or the
 * absent teacher returned). Leaves the lesson+date open for a fresh
 * AssignSubstitute call rather than deleting the row, so the audit trail
 * keeps the original assignment.
 */
class CancelSubstitutionAssignment
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(SubstitutionAssignment $assignment, User $actor): void
    {
        if ($assignment->status === SubstitutionAssignment::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'assignment' => 'ეს ჩანაცვლება უკვე გაუქმებულია.',
            ]);
        }

        $assignment->status = SubstitutionAssignment::STATUS_CANCELLED;
        $assignment->save();

        $this->auditLogger->record($assignment->tenant_id, 'substitution.cancelled', $assignment, $actor->id, [
            'lesson_id' => $assignment->lesson_id,
            'substitute_teacher_id' => $assignment->substitute_teacher_id,
            'date' => $assignment->date->toDateString(),
        ]);
    }
}
