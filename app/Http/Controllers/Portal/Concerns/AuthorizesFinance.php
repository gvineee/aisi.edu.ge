<?php

namespace App\Http\Controllers\Portal\Concerns;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Finance access rules (CLAUDE.md #3, #4): staff = accountant / director /
 * admin with confirmed 2FA; a guardian sees only children whose active link
 * carries can_view_financial.
 */
trait AuthorizesFinance
{
    /**
     * @return array<int, string>
     */
    protected static function financeStaffRoles(): array
    {
        return [TenantMembership::ROLE_ACCOUNTANT, TenantMembership::ROLE_DIRECTOR, TenantMembership::ROLE_ADMIN];
    }

    protected function isFinanceStaffRole(int $tenantId, User $user): bool
    {
        return TenantMembership::userHasAnyActiveRole($tenantId, $user->id, self::financeStaffRoles());
    }

    protected function isFinanceStaff(int $tenantId, User $user): bool
    {
        return $this->isFinanceStaffRole($tenantId, $user) && $user->two_factor_confirmed_at !== null;
    }

    protected function authorizeFinanceStaff(int $tenantId, User $user): void
    {
        abort_unless($this->isFinanceStaff($tenantId, $user), 403);
    }

    /**
     * @return Collection<int, Student>
     */
    protected function financialChildrenOf(int $tenantId, User $user): Collection
    {
        if (! TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_GUARDIAN)) {
            return collect();
        }

        return GuardianLink::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)
            ->where('is_active', true)->where('can_view_financial', true)
            ->with('student.schoolClass')->get()->pluck('student')->filter()->values();
    }

    /**
     * "45.50" → 4550 without floating point.
     */
    protected static function moneyToMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', trim($amount)), 2), 2, '0');

        return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
