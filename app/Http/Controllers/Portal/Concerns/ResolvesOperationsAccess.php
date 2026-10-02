<?php

namespace App\Http\Controllers\Portal\Concerns;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Shared access helpers for the operations modules (clubs, transport,
 * meals): management roles run them; guardians act only for their own
 * active children.
 */
trait ResolvesOperationsAccess
{
    /**
     * @var array<int, string>
     */
    protected array $managementRoles = [
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    protected function isManagement(int $tenantId, User $user): bool
    {
        return TenantMembership::userHasAnyActiveRole($tenantId, $user->id, $this->managementRoles);
    }

    /**
     * @return Collection<int, Student>
     */
    protected function ownChildren(int $tenantId, User $user): Collection
    {
        if (! TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_GUARDIAN)) {
            return collect();
        }

        return GuardianLink::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['student' => fn ($query) => $query->where('is_active', true)->with('schoolClass')])
            ->get()
            ->pluck('student')
            ->filter()
            ->values();
    }

    protected function ownChild(int $tenantId, User $user, int $studentId): ?Student
    {
        return $this->ownChildren($tenantId, $user)->firstWhere('id', $studentId);
    }
}
