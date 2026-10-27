<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\PermissionMatrix;
use App\Domain\Tenancy\PortalContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * „როლები და უფლებები“: one table of which role reaches which module, for
 * the people who hand out roles. A role's permissions are fixed in code
 * (reviewed and tested); who holds which role is changed on the members
 * page.
 */
class PermissionMatrixController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $currentTenant, PortalContext $portalContext, PermissionMatrix $matrix): Response
    {
        $tenantId = $currentTenant->get()->id;
        abort_unless(TenantMembership::userHasAnyActiveRole($tenantId, $request->user()->id, PortalContext::MEMBER_ACCESS_ROLES), 403);

        return Inertia::render('portal/permissions/index', $matrix->build($portalContext->enabledFeatures($tenantId), $matrix->membersByRole($tenantId)));
    }
}
