<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;

/**
 * Deactivates a TenantMembership (is_active = false). This is a
 * "significant change" under CLAUDE.md invariant #7, so it is always
 * audit-logged. Deactivating, rather than deleting, keeps history intact
 * and immediately blocks the role everywhere PortalContext/policies check
 * TenantMembership::userHasActiveRole().
 */
class RevokeTenantMembership
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(TenantMembership $membership, User $actor): void
    {
        if (! $membership->is_active) {
            return;
        }

        $membership->is_active = false;
        $membership->save();

        $this->auditLogger->record($membership->tenant_id, 'membership.revoked', $membership, $actor->id, [
            'role' => $membership->role,
            'user_id' => $membership->user_id,
        ]);
    }
}
