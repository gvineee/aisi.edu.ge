<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline secure response headers (CLAUDE-PLATFORM-MODULES §15). A
 * Content-Security-Policy is deliberately not set yet: Inertia/Vite and the
 * SSR bootstrap need a nonce strategy first, and a half-working CSP would
 * be claimed compliance it does not deliver (docs/security-asvs-checklist.md).
 * HSTS is sent only over HTTPS so local HTTP development keeps working.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
