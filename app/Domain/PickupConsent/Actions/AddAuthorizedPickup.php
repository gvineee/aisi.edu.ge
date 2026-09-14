<?php

namespace App\Domain\PickupConsent\Actions;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\PickupConsent\Models\AuthorizedPickup;
use App\Models\User;

/**
 * Adds a person a guardian authorizes to pick up their child
 * (CLAUDE-PLATFORM-MODULES.md §7). The controller is responsible for
 * checking {@see GuardianLink} before calling this — this action only
 * writes the row and audits it, it does not re-derive authorization, so it
 * must never be called from anywhere that hasn't already verified the
 * caller is the student's own active guardian.
 */
class AddAuthorizedPickup
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Student $student, User $guardian, string $fullName, string $relationship, ?string $idDocumentNumber): AuthorizedPickup
    {
        $pickup = new AuthorizedPickup([
            'student_id' => $student->id,
            'full_name' => $fullName,
            'relationship' => $relationship,
            'id_document_number' => $idDocumentNumber,
            'is_active' => true,
            'added_by' => $guardian->id,
        ]);
        $pickup->tenant_id = $student->tenant_id;
        $pickup->save();

        $this->auditLogger->record($student->tenant_id, 'authorized_pickup.added', $pickup, $guardian->id, [
            'student_id' => $student->id,
            'full_name' => $fullName,
            'relationship' => $relationship,
        ]);

        return $pickup;
    }
}
