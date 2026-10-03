<?php

use App\Http\Middleware\EnsureAdminMfa;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsurePlatformOperator;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoIndexPrivateAreas;
use App\Http\Middleware\ResolveTenant;
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

        // Provider notifications are authenticated by their body signature,
        // not by a session, so they carry no CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->alias([
            'feature' => EnsureFeatureEnabled::class,
            'platform' => EnsurePlatformOperator::class,
        ]);

        $middleware->web(prepend: [
            ResolveTenant::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            NoIndexPrivateAreas::class,
            EnsureAdminMfa::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
