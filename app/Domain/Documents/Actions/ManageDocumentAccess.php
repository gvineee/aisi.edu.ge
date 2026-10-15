<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\DocumentAccess;
use App\Domain\Documents\Models\DocumentAccessGrant;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Grants and revokes workspace/document access for one staff member or for
 * every holder of a staff role. Both are audited (CLAUDE.md #7). Granting
 * the same target the same level twice is a no-op, not a duplicate row.
 */
class ManageDocumentAccess
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function grant(int $tenantId, string $resourceType, int $resourceId, ?int $userId, ?string $role, string $permission, User $actor): DocumentAccessGrant
    {
        if (! in_array($resourceType, [DocumentAccessGrant::RESOURCE_WORKSPACE, DocumentAccessGrant::RESOURCE_DOCUMENT], true)) {
            throw ValidationException::withMessages(['resource_type' => 'უცნობი რესურსი.']);
        }

        if (! in_array($permission, DocumentAccessGrant::LEVELS, true)) {
            throw ValidationException::withMessages(['permission' => 'უცნობი წვდომის დონე.']);
        }

        if (($userId === null) === ($role === null)) {
            throw ValidationException::withMessages(['user_id' => 'აირჩიეთ ან თანამშრომელი, ან როლი.']);
        }

        if ($role !== null && ! in_array($role, DocumentAccess::STAFF_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'წვდომა მხოლოდ თანამშრომლის როლს შეიძლება მიეცეს.']);
        }

        if ($userId !== null && ! TenantMembership::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('is_active', true)->whereIn('role', DocumentAccess::STAFF_ROLES)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'ეს მომხმარებელი სკოლის აქტიური თანამშრომელი არ არის.']);
        }

        $grant = DocumentAccessGrant::query()
            ->where('tenant_id', $tenantId)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->where('permission', $permission)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id')->where('role', $role))
            ->first();

        if ($grant !== null) {
            return $grant;
        }

        $grant = new DocumentAccessGrant([
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'user_id' => $userId,
            'role' => $userId === null ? $role : null,
            'permission' => $permission,
            'granted_by' => $actor->id,
        ]);
        $grant->tenant_id = $tenantId;
        $grant->save();

        $this->auditLogger->record($tenantId, 'document_access.granted', $grant, $actor->id, [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'user_id' => $userId,
            'role' => $grant->role,
            'permission' => $permission,
        ]);

        return $grant;
    }

    public function revoke(DocumentAccessGrant $grant, User $actor): void
    {
        $this->auditLogger->record($grant->tenant_id, 'document_access.revoked', $grant, $actor->id, [
            'resource_type' => $grant->resource_type,
            'resource_id' => $grant->resource_id,
            'user_id' => $grant->user_id,
            'role' => $grant->role,
            'permission' => $grant->permission,
        ]);

        $grant->delete();
    }
}
