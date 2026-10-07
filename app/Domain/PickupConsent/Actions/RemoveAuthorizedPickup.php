<?php

namespace App\Domain\PickupConsent\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\PickupConsent\Models\AuthorizedPickup;
use App\Domain\PickupConsent\Models\PickupCode;
use App\Models\User;
use Illuminate\Support\Carbon;

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

        // Codes already issued for this person stop working immediately.
        PickupCode::query()->where('tenant_id', $pickup->tenant_id)->where('authorized_pickup_id', $pickup->id)
            ->whereNull('consumed_at')->whereNull('cancelled_at')->update(['cancelled_at' => Carbon::now()]);

        $this->auditLogger->record($pickup->tenant_id, 'authorized_pickup.removed', $pickup, $guardian->id, [
            'student_id' => $pickup->student_id,
            'full_name' => $pickup->full_name,
        ]);
    }
}
