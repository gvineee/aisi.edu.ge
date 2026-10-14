<?php

namespace App\Http\Middleware;

use App\Domain\Content\Models\LegacyRedirect;
use App\Domain\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a would-be 404 into the recorded permanent redirect for the current
 * tenant's old-site URL. Runs after the route so it costs nothing on normal
 * pages and can never shadow a real route or published page.
 */
class RedirectLegacyUrls
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $tenantId = $this->currentTenant->id();

        if ($response->getStatusCode() !== 404 || $tenantId === null || ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $response;
        }

        $normalized = LegacyRedirect::normalizePath($request->getPathInfo());

        $redirect = LegacyRedirect::query()
            ->where('tenant_id', $tenantId)
            ->where('source_hash', LegacyRedirect::hashPath($normalized))
            ->first();

        if ($redirect === null || $redirect->target_path === $normalized) {
            return $response;
        }

        LegacyRedirect::query()->whereKey($redirect->id)->update([
            'hits' => DB::raw('hits + 1'),
            'last_hit_at' => now(),
        ]);

        return redirect()->to($redirect->target_path, $redirect->status_code);
    }
}
