<?php

use App\Http\Middleware\EnsureAdminMfa;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsurePlatformOperator;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoIndexPrivateAreas;
use App\Http\Middleware\RedirectLegacyUrls;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Which proxies: config/trustedproxy.php. Never X-Forwarded-Host —
        // the tenant comes from the Host header.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);

        // Provider notifications are authenticated by their body signature,
        // not by a session, so they carry no CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->alias([
            'feature' => EnsureFeatureEnabled::class,
            'platform' => EnsurePlatformOperator::class,
            'reauth' => RequireRecentPassword::class,
        ]);

        $middleware->web(prepend: [
            ResolveTenant::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            RedirectLegacyUrls::class,
            AddLinkHeadersForPreloadedAssets::class,
            NoIndexPrivateAreas::class,
            EnsureAdminMfa::class,
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
