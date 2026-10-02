<?php

namespace App\Domain\Health\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Health\HealthAccess;
use App\Domain\Health\Models\HealthPermissionGrant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Grants/revokes a single health capability. Like a role change, every
 * grant and revocation is audit-logged.
 */
class ManageHealthGrants
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly HealthAccess $access,
    ) {}

    public function grant(int $tenantId, User $actor, User $grantee, string $capability): HealthPermissionGrant
    {
        if (! in_array($capability, HealthPermissionGrant::CAPABILITIES, true)) {
            throw ValidationException::withMessages(['capability' => 'უცნობი უფლება.']);
        }

        if (! $this->access->isStaff($tenantId, $grantee)) {
            throw ValidationException::withMessages(['user_id' => 'უფლება შეიძლება მიეცეს მხოლოდ სკოლის აქტიურ თანამშრომელს.']);
        }

        $existing = HealthPermissionGrant::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $grantee->id)
            ->where('capability', $capability)
            ->whereNull('revoked_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $grant = new HealthPermissionGrant([
            'user_id' => $grantee->id,
            'capability' => $capability,
            'granted_by' => $actor->id,
        ]);
        $grant->tenant_id = $tenantId;
        $grant->save();

        $this->auditLogger->record($tenantId, 'health_grant.granted', $grant, $actor->id, [
            'user_id' => $grantee->id,
            'capability' => $capability,
        ]);

        return $grant;
    }

    public function revoke(HealthPermissionGrant $grant, User $actor): void
    {
        if ($grant->revoked_at !== null) {
            return;
        }

        $grant->revoked_at = Carbon::now();
        $grant->revoked_by = $actor->id;
        $grant->save();

        $this->auditLogger->record($grant->tenant_id, 'health_grant.revoked', $grant, $actor->id, [
            'user_id' => $grant->user_id,
            'capability' => $grant->capability,
        ]);
    }
}
