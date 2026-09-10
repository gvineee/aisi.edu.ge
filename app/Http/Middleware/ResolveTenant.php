<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for this request from the trusted request host, and
 * nothing else. `tenant_domains.domain` is the only lookup key — a tenant_id
 * sent in the query string, form body, or a header is never honoured here.
 *
 * Unknown hosts get a plain 404 rather than falling back to any tenant, so a
 * misconfigured domain can never accidentally serve someone else's school.
 */
class ResolveTenant
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        $domain = TenantDomain::query()
            ->with('tenant')
            ->where('domain', $host)
            ->first();

        if (! $domain || ! $domain->tenant || ! $domain->tenant->is_active) {
            abort(404);
        }

        $this->currentTenant->set($domain->tenant);

        try {
            return $next($request);
        } finally {
            $this->currentTenant->clear();
        }
    }
}
