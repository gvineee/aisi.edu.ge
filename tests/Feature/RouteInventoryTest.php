<?php

namespace Tests\Feature;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * Route inventory (docs/security-asvs-checklist.md V8): every route is
 * either deliberately public — listed below with the reason it may be —
 * or sits behind authentication. A new route that forgets its middleware
 * fails here instead of quietly becoming public.
 */
class RouteInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_route_is_authenticated_or_explicitly_public(): void
    {
        $router = $this->app->make(Router::class);
        $unexpected = [];

        foreach ($router->getRoutes() as $route) {
            if ($this->routeRequiresLogin($router, $route) || $this->isAllowedPublic($route)) {
                continue;
            }

            $unexpected[] = implode('|', $route->methods()).' '.$route->uri();
        }

        $this->assertSame([], $unexpected, "Routes without authentication that are not on the public allowlist:\n".implode("\n", $unexpected));
    }

    /**
     * Every authenticated route, probed as a guest with placeholder
     * parameters, ends at the login page (or a 4xx) — never content.
     */
    public function test_guests_reach_no_authenticated_route(): void
    {
        $this->withoutVite();
        $router = $this->app->make(Router::class);
        $leaks = [];
        $probed = 0;

        foreach ($router->getRoutes() as $route) {
            if (! $this->routeRequiresLogin($router, $route) || $route->getDomain() !== null) {
                continue;
            }

            $probed++;
            $uri = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');
            $method = $route->methods()[0];
            $response = $this->call($method, $uri);
            $status = $response->getStatusCode();
            $toLogin = $response->isRedirect() && str_contains((string) $response->headers->get('Location'), '/login');

            if (! $toLogin && ! in_array($status, [401, 403, 404, 405, 419], true)) {
                $leaks[] = "{$method} {$uri} -> {$status} ".$response->headers->get('Location');
            }
        }

        $this->assertGreaterThan(200, $probed, 'The probe should cover the whole authenticated surface.');
        $this->assertSame([], $leaks, "Authenticated routes that answered a guest:\n".implode("\n", $leaks));
    }

    private function routeRequiresLogin(Router $router, Route $route): bool
    {
        foreach ($router->gatherRouteMiddleware($route) as $middleware) {
            $class = is_string($middleware) ? explode(':', $middleware)[0] : '';

            // `guest` (RedirectIfAuthenticated) does not count.
            if ($class === 'auth' || is_a($class, Authenticate::class, true)) {
                return true;
            }
        }

        return false;
    }

    private function isAllowedPublic(Route $route): bool
    {
        $uri = $route->uri();

        foreach (self::PUBLIC_PATTERNS as $pattern) {
            if (preg_match($pattern, $uri) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deliberately public URIs, by purpose. Keep each entry narrow.
     */
    private const PUBLIC_PATTERNS = [
        // Sign-in, password reset, registration, 2FA challenge, passkeys (Fortify/starter kit).
        '#^(login|register|forgot-password|reset-password(/\{token\})?|two-factor-challenge)$#',
        '#^passkeys/login(/options)?$#',
        '#^\.well-known/passkey-endpoints$#',
        // Health check for uptime monitoring.
        '#^up$#',
        // Public school website: home, news, teachers, catalogue, CMS pages, SEO files, 404 fallback.
        '#^/$#',
        '#^(news|teachers)(/\{slug\})?$#',
        '#^library$#',
        '#^\{slug\}$#',
        // The same public pages in the site's other languages (/en, /ru).
        '#^\{locale\}(/news(/\{slug\})?|/\{slug\})?$#',
        '#^\{fallbackPlaceholder\}$#',
        '#^(sitemap\.xml|robots\.txt)$#',
        // Admissions enquiry and visit booking; cancellation is a signed link.
        '#^admissions/(leads|visit-slots)$#',
        '#^admissions/visits/\{booking\}/cancel$#',
        // Invitation acceptance (unguessable token) and report-card authenticity check (code).
        '#^invitations/\{token\}(/accept)?$#',
        '#^verify/report-card/\{code\}$#',
        // Payment provider webhooks — verified by signature, not by session.
        '#^webhooks/payments/\{provider\}$#',
    ];
}
