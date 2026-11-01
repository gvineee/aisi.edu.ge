<?php

use App\Http\ErrorPage;
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
use App\Http\Middleware\ThrottlePerRoute;
use App\Http\SessionLostResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            'throttle' => ThrottlePerRoute::class,
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

        // A form sent after the session ended (signed out in another tab,
        // expired, stale CSRF token) must not come back as an ordinary
        // Inertia redirect: the form's onSuccess would call that "saved".
        $exceptions->render(fn (AuthenticationException $e, Request $request) => SessionLostResponse::for($request));
        $exceptions->render(fn (HttpException $e, Request $request) => match ($e->getStatusCode()) {
            419 => SessionLostResponse::for($request),
            403 => SessionLostResponse::landingRefused($request),
            // Too many tries: an error on the form (onError), never a plain
            // redirect the form would read as "sent".
            429 => $request->header('X-Inertia') && ! $request->isMethod('GET')
                ? back()->withErrors(['rate_limit' => 'ძალიან ბევრი მცდელობა მოკლე დროში. სცადეთ ერთ წუთში თავიდან.'])
                : null,
            default => null,
        });

        $exceptions->respond(fn (Response $response, Throwable $e, Request $request) => ErrorPage::respond($response, $request));
    })->create();
