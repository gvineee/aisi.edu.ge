<?php

namespace App\Domain\Health\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\Health\HealthAccess;
use App\Domain\Health\Models\HealthEmergencyAccess;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * "Break glass": a staff member without a health grant opens the full
 * record of one student for a limited time, stating why. It is logged,
 * expires by itself, and waits in the review queue for a health.audit.view
 * holder. The reason is stored encrypted and kept out of the audit log.
 */
class ManageEmergencyAccess
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly HealthAccess $access,
    ) {}

    public function request(int $tenantId, User $actor, Student $student, string $reason): HealthEmergencyAccess
    {
        if (! $this->access->isStaff($tenantId, $actor)) {
            abort(403);
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'მიუთითეთ გადაუდებელი წვდომის მიზეზი (მინიმუმ 10 სიმბოლო).']);
        }

        $emergency = new HealthEmergencyAccess([
            'student_id' => $student->id,
            'user_id' => $actor->id,
            'reason' => trim($reason),
            'expires_at' => Carbon::now()->addMinutes((int) config('health.emergency_access_minutes', 120)),
        ]);
        $emergency->tenant_id = $tenantId;
        $emergency->save();

        $this->access->log($tenantId, $student, $actor, 'emergency_access_opened', HealthAccess::BASIS_EMERGENCY, $emergency);
        $this->auditLogger->record($tenantId, 'health.emergency_access_opened', $emergency, $actor->id, [
            'student_id' => $student->id,
            'expires_at' => $emergency->expires_at->toIso8601String(),
        ]);

        return $emergency;
    }

    public function review(HealthEmergencyAccess $emergency, User $reviewer, ?string $note): void
    {
        if ($emergency->user_id === $reviewer->id) {
            throw ValidationException::withMessages(['review' => 'საკუთარ გადაუდებელ წვდომას ვერ განიხილავთ.']);
        }

        if ($emergency->reviewed_at !== null) {
            return;
        }

        $emergency->reviewed_by = $reviewer->id;
        $emergency->reviewed_at = Carbon::now();
        $emergency->review_note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $emergency->save();

        $this->auditLogger->record($emergency->tenant_id, 'health.emergency_access_reviewed', $emergency, $reviewer->id, [
            'student_id' => $emergency->student_id,
        ]);
    }
}
