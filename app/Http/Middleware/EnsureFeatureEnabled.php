<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\FeatureEntitlement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:<name>` — blocks a module's routes on the server when the
 * current tenant does not have that feature enabled (CLAUDE-PLATFORM-MODULES
 * §11, §17.9). Hiding the nav item is not enough. Disabling a feature never
 * deletes its data; it only closes the routes.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = app(CurrentTenant::class)->get();

        abort_unless($tenant !== null && FeatureEntitlement::isEnabled($tenant->id, $feature), 404);

        return $next($request);
    }
}
