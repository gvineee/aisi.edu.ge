<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CLAUDE.md #4 / §15: privileged tenant roles need MFA. A signed-in user
 * with an active admin, director or accountant membership here who has not
 * confirmed two-factor authentication is sent to the security settings to
 * enrol before using the private portal. Settings, logout and the public
 * site stay reachable, so nobody is locked out — enrolment is the way in.
 * Finance, health and platform operators already have their own checks.
 */
class EnsureAdminMfa
{
    /**
     * §15: admin, director and accountant are privileged tenant roles.
     *
     * @var array<int, string>
     */
    private const PRIVILEGED_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACCOUNTANT,
    ];

    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenantId = $this->currentTenant->id();

        if (
            config('security.admin_mfa_required')
            && $user !== null
            && $tenantId !== null
            && $user->two_factor_confirmed_at === null
            && ($request->is('portal', 'portal/*', 'dashboard', 'lessons/*'))
            && TenantMembership::userHasAnyActiveRole($tenantId, $user->id, self::PRIVILEGED_ROLES)
        ) {
            return redirect()->route('security.edit')->with('toast', [
                'type' => 'warning',
                'message' => 'ადმინისტრატორის ანგარიშისთვის ჩართეთ ორფაქტორიანი ავთენტიფიკაცია.',
            ]);
        }

        return $next($request);
    }
}
