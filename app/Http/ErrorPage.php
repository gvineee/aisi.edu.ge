<?php

namespace App\Http;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The school's branded error screen for 403/404/419/429/500/503 (audit
 * A11) instead of the framework's bare "404 Not Found". The status code is
 * kept, nothing about the refused record is shown, JSON callers keep JSON,
 * and with APP_DEBUG a 500 still shows the developer page.
 */
class ErrorPage
{
    private const STATUSES = [403, 404, 419, 429, 500, 503];

    public static function respond(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! in_array($status, self::STATUSES, true)
            || $request->expectsJson()
            || $request->is('api/*', 'webhooks/*')
            || ($status >= 500 && config('app.debug'))) {
            return $response;
        }

        $tenants = app(CurrentTenant::class);
        $resolvedHere = false;

        // An unknown URL fails before the web middleware runs, so the school
        // is looked up here to keep its name, logo and colours on the page.
        if (! $tenants->isResolved()) {
            $domain = TenantDomain::query()->with('tenant')->where('domain', $request->getHost())->first();

            if ($domain !== null && ! $domain->isPending() && $domain->tenant?->is_active) {
                $tenants->set($domain->tenant);
                $resolvedHere = true;
            }
        }

        try {
            Inertia::share(app(HandleInertiaRequests::class)->share($request));

            return Inertia::render('error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        } catch (Throwable) {
            // Never let the error page hide the original error.
            return $response;
        } finally {
            if ($resolvedHere) {
                $tenants->clear();
            }
        }
    }
}
