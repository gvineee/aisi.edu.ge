<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves "which of the current user's real, active roles in this tenant
 * are they viewing the portal as right now" — the one place that answers
 * this question, shared by the dashboard (which screen to render), the
 * layout nav (what's visible), and the role-switch endpoint (what's a valid
 * switch target). A role hidden from navigation is never itself the
 * authorization boundary — every controller still re-checks
 * TenantMembership itself (docs/04 §6's explicit warning).
 */
class PortalContext
{
    public const SESSION_KEY = 'portal_active_role';

    /**
     * Roles ordered by which one a multi-role user probably wants to land
     * on first. Order is a UX default only, not a privilege ranking.
     *
     * @var array<int, string>
     */
    private const ROLE_PRIORITY = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_TEACHER,
        TenantMembership::ROLE_ACCOUNTANT,
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_GUARDIAN,
        TenantMembership::ROLE_STUDENT,
    ];

    /**
     * @var array<string, string>
     */
    private const ROLE_LABELS = [
        TenantMembership::ROLE_ADMIN => 'ადმინისტრატორი',
        TenantMembership::ROLE_DIRECTOR => 'დირექტორი',
        TenantMembership::ROLE_ACADEMIC_MANAGER => 'აკადემიური მენეჯერი',
        TenantMembership::ROLE_TEACHER => 'მასწავლებელი',
        TenantMembership::ROLE_ACCOUNTANT => 'ბუღალტერი',
        TenantMembership::ROLE_EDITOR => 'რედაქტორი',
        TenantMembership::ROLE_GUARDIAN => 'მშობელი',
        TenantMembership::ROLE_STUDENT => 'მოსწავლე',
    ];

    /**
     * Roles that get the "დოკუმენტები" nav destination — kept in sync with
     * DocumentController::STAFF_ROLES; duplicated as plain strings here
     * rather than importing that controller, since nav *visibility* is a UI
     * concern, not the authorization check itself (that check still lives,
     * and is re-run, in DocumentController).
     *
     * @var array<int, string>
     */
    private const DOCUMENT_ACCESS_ROLES = [
        TenantMembership::ROLE_TEACHER,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
        TenantMembership::ROLE_ACCOUNTANT,
        TenantMembership::ROLE_EDITOR,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    /**
     * @return array<int, string> active role strings, in ROLE_PRIORITY order
     */
    public function activeRoles(int $tenantId, int $userId): array
    {
        $held = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('role')
            ->all();

        return array_values(array_filter(self::ROLE_PRIORITY, fn (string $role) => in_array($role, $held, true)));
    }

    /**
     * Resolves the role this request should render as, persisting a
     * user-chosen role in the session so it survives to the next request.
     * Never trusts a client-supplied role directly — only ever narrows to
     * one already present in activeRoles().
     */
    public function resolveActiveRole(Request $request, int $tenantId, int $userId): ?string
    {
        $available = $this->activeRoles($tenantId, $userId);

        if ($available === []) {
            return null;
        }

        $sessionRole = $request->session()->get(self::SESSION_KEY);

        if (is_string($sessionRole) && in_array($sessionRole, $available, true)) {
            return $sessionRole;
        }

        return $available[0];
    }

    /**
     * Called by the role-switch endpoint only, after it has already
     * verified $role is one of the user's own activeRoles().
     */
    public function setActiveRole(Request $request, string $role): void
    {
        $request->session()->put(self::SESSION_KEY, $role);
    }

    /**
     * @return array<int, array{key: string, label: string, href: string, icon: string}>
     */
    public function navItemsFor(string $activeRole): array
    {
        $items = [
            ['key' => 'dashboard', 'label' => 'დღეს', 'href' => route('dashboard'), 'icon' => 'home'],
        ];

        if (in_array($activeRole, self::DOCUMENT_ACCESS_ROLES, true)) {
            $items[] = ['key' => 'documents', 'label' => 'დოკუმენტები', 'href' => route('documents.index'), 'icon' => 'documents'];
        }

        return $items;
    }

    /**
     * @return array{activeRole: string|null, availableRoles: array<int, array{value: string, label: string}>, navItems: array<int, array{key: string, label: string, href: string, icon: string}>}
     */
    public function propsFor(Request $request, Tenant $tenant, User $user): array
    {
        $activeRole = $this->resolveActiveRole($request, $tenant->id, $user->id);
        $available = $this->activeRoles($tenant->id, $user->id);

        return [
            'activeRole' => $activeRole,
            'availableRoles' => array_map(fn (string $role) => ['value' => $role, 'label' => self::ROLE_LABELS[$role] ?? $role], $available),
            'navItems' => $activeRole !== null ? $this->navItemsFor($activeRole) : [],
        ];
    }
}
