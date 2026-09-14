<?php

namespace App\Domain\Timetable\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Timetable\Models\StaffAbsence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Records that a teacher is absent for a date range. Callable only by
 * admin/academic_manager/director (enforced by SubstitutionController, not
 * here — this action trusts its caller already checked the role, same
 * split as CreateStudent/CreateAcademicYear).
 */
class RecordStaffAbsence
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(
        int $tenantId,
        User $actor,
        User $teacher,
        Carbon $startsOn,
        Carbon $endsOn,
        ?string $reason,
    ): StaffAbsence {
        if ($endsOn->lt($startsOn)) {
            throw ValidationException::withMessages([
                'ends_on' => 'დასრულების თარიღი არ შეიძლება იყოს დაწყების თარიღზე ადრე.',
            ]);
        }

        $absence = new StaffAbsence([
            'user_id' => $teacher->id,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'reason' => $reason,
            'status' => StaffAbsence::STATUS_REPORTED,
            'created_by' => $actor->id,
        ]);
        $absence->tenant_id = $tenantId;
        $absence->save();

        $this->auditLogger->record($tenantId, 'staff_absence.reported', $absence, $actor->id, [
            'teacher_id' => $teacher->id,
            'starts_on' => $absence->starts_on->toDateString(),
            'ends_on' => $absence->ends_on->toDateString(),
        ]);

        return $absence;
    }
}
