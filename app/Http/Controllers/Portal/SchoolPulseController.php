<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Analytics\SchoolPulse;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "სკოლის პულსი" — `/portal/director` (CLAUDE-PLATFORM-MODULES §13).
 */
class SchoolPulseController extends Controller
{
    /**
     * @var array<int, string>
     */
    public const ACCESS_ROLES = [
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ADMIN,
    ];

    public function __invoke(Request $request, CurrentTenant $currentTenant, SchoolPulse $pulse): Response
    {
        $tenant = $currentTenant->get();
        abort_unless(TenantMembership::userHasAnyActiveRole($tenant->id, $request->user()->id, self::ACCESS_ROLES), 403);

        return Inertia::render('portal/director/pulse', $pulse->compute($tenant->id));
    }
}
