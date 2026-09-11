<?php

namespace App\Http\Controllers\Public;

use App\Domain\Tenancy\Actions\AcceptTenantInvitation;
use App\Domain\Tenancy\Actions\InvitationAlreadyAcceptedException;
use App\Domain\Tenancy\Actions\InvitationExpiredException;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantInvitation;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\AcceptInvitationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public, unauthenticated "accept invitation" flow. TenantInvitation
 * uses BelongsToTenant, so every lookup here is already implicitly scoped
 * to the tenant resolved from the request host — but we still assert it
 * explicitly (CLAUDE.md invariant #2: never rely on a single layer for
 * tenant isolation), so a token minted for one school's domain can never
 * be accepted while browsing another school's domain.
 */
class InvitationController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const ROLE_LABELS = [
        TenantMembership::ROLE_STUDENT => 'მოსწავლე',
        TenantMembership::ROLE_GUARDIAN => 'მშობელი',
        TenantMembership::ROLE_TEACHER => 'მასწავლებელი',
        TenantMembership::ROLE_ACADEMIC_MANAGER => 'აკადემიური მენეჯერი',
        TenantMembership::ROLE_ACCOUNTANT => 'ბუღალტერი',
        TenantMembership::ROLE_EDITOR => 'რედაქტორი',
        TenantMembership::ROLE_ADMIN => 'ადმინისტრატორი',
        TenantMembership::ROLE_DIRECTOR => 'დირექტორი',
    ];

    public function show(CurrentTenant $currentTenant, string $token): Response
    {
        $tenant = $currentTenant->get();
        $invitation = TenantInvitation::query()->where('token', $token)->first();

        // A token that doesn't exist at all, and one that belongs to a
        // different tenant, look identical from here on out: both come
        // back null from the tenant-scoped query above, so neither ever
        // reveals whether "wrong tenant" or "never existed" is the case.
        abort_if($invitation === null, 404);

        if ($invitation->isAccepted()) {
            return Inertia::render('auth/accept-invitation', [
                'status' => 'accepted',
            ]);
        }

        if ($invitation->isExpired()) {
            return Inertia::render('auth/accept-invitation', [
                'status' => 'expired',
            ]);
        }

        $userExists = User::query()->where('email', $invitation->email)->exists();

        return Inertia::render('auth/accept-invitation', [
            'status' => 'valid',
            'token' => $invitation->token,
            'email' => $invitation->email,
            'roleLabel' => self::ROLE_LABELS[$invitation->role] ?? $invitation->role,
            'tenantName' => $tenant->name,
            'userExists' => $userExists,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function accept(AcceptInvitationRequest $request, CurrentTenant $currentTenant, string $token, AcceptTenantInvitation $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $invitation = TenantInvitation::query()->where('token', $token)->firstOrFail();
        abort_unless($invitation->tenant_id === $tenant->id, 404);

        try {
            $user = $action->handle(
                $invitation,
                $request->string('name')->toString() ?: null,
                $request->string('password')->toString() ?: null,
            );
        } catch (InvitationAlreadyAcceptedException|InvitationExpiredException) {
            return redirect()->route('invitations.show', $token);
        }

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
