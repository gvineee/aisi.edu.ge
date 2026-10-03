<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline secure response headers (CLAUDE-PLATFORM-MODULES §15).
 *
 * Content-Security-Policy uses a per-request nonce shared with Vite's tags
 * and the one inline bootstrap script in app.blade.php. It ships in
 * `report-only` mode (SECURITY_CSP) until it has been verified in a real
 * browser on every page; switch to `enforce` after that. It is skipped
 * while the Vite dev server is hot. HSTS is sent only over HTTPS so local
 * HTTP development keeps working.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('security.csp');
        $csp = $mode !== 'off' && ! Vite::isRunningHot();
        $nonce = $csp ? Vite::useCspNonce() : null;

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($nonce !== null) {
            $headers->set($mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', self::policy($nonce));
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    public static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // React style props and Tailwind need inline styles.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
        ]);
    }
}
