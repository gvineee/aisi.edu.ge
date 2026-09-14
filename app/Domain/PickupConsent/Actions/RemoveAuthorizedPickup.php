<?php

namespace App\Domain\PickupConsent\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\PickupConsent\Models\AuthorizedPickup;
use App\Models\User;

/**
 * Revokes a person's pickup authorization. Soft-revoke (is_active = false)
 * rather than a delete, so the roster keeps a record of who was ever
 * authorized for this child (CLAUDE-PLATFORM-MODULES.md §7). As with
 * {@see AddAuthorizedPickup}, the controller must already have verified the
 * caller is the student's own active guardian before calling this.
 */
class RemoveAuthorizedPickup
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(AuthorizedPickup $pickup, User $guardian): void
    {
        if (! $pickup->is_active) {
            return;
        }

        $pickup->is_active = false;
        $pickup->save();

        $this->auditLogger->record($pickup->tenant_id, 'authorized_pickup.removed', $pickup, $guardian->id, [
            'student_id' => $pickup->student_id,
            'full_name' => $pickup->full_name,
        ]);
    }
}
