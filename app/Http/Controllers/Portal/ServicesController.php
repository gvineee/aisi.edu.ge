<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use App\Domain\Tenancy\PortalContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "სერვისები" hub: links to whichever operations modules this school has
 * switched on. Each module route is separately gated by `feature:`.
 */
class ServicesController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant, PortalContext $portalContext): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($portalContext->activeRoles($tenant->id, $request->user()->id) !== [], 403);

        $enabled = $portalContext->enabledFeatures($tenant->id);

        return Inertia::render('portal/services/index', [
            'modules' => array_values(array_filter([
                in_array(FeatureEntitlement::FEATURE_CLUBS, $enabled, true) ? ['key' => 'clubs', 'href' => route('clubs.index')] : null,
                in_array(FeatureEntitlement::FEATURE_TRANSPORT, $enabled, true) ? ['key' => 'transport', 'href' => route('transport.index')] : null,
                in_array(FeatureEntitlement::FEATURE_MEALS, $enabled, true) ? ['key' => 'meals', 'href' => route('meals.index')] : null,
            ])),
        ]);
    }
}
