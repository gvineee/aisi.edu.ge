<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CLAUDE.md: the private portal must not be indexed. robots.txt is only a
 * hint (and not access control), so every response from a private or
 * token-bearing area also carries an explicit X-Robots-Tag.
 */
class NoIndexPrivateAreas
{
    /**
     * Path patterns (Request::is syntax) that are never indexable — the
     * same areas RobotsController lists as Disallow.
     *
     * @var array<int, string>
     */
    public const PATTERNS = [
        'portal', 'portal/*',
        'dashboard',
        'settings', 'settings/*',
        'login', 'register', 'forgot-password', 'reset-password', 'reset-password/*',
        'two-factor-challenge', 'user/*', 'email/*',
        'invitations/*',
        'verify/*',
        'platform-admin', 'platform-admin/*',
        'webhooks/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is(...self::PATTERNS)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
