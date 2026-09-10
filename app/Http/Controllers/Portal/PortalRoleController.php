<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\PortalContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lets a user who genuinely holds more than one active role in this tenant
 * choose which one to view the portal as. The requested role is only ever
 * accepted if PortalContext::activeRoles() already lists it for this exact
 * user/tenant — never trusted from the client beyond that check.
 */
class PortalRoleController extends Controller
{
    public function update(Request $request, CurrentTenant $currentTenant, PortalContext $portalContext): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $requestedRole = $request->string('role')->toString();
        $available = $portalContext->activeRoles($tenant->id, $user->id);

        abort_unless(in_array($requestedRole, $available, true), 403);

        $portalContext->setActiveRole($request, $requestedRole);

        return redirect()->route('dashboard');
    }
}
