<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Privileged re-authentication (CLAUDE-PLATFORM-MODULES §15) for changes to
 * roles, invitations, health permissions and support access. Like
 * Laravel's `password.confirm`, but a POST that needs confirmation returns
 * the user to the page they came from afterwards (not to the POST URL as a
 * GET, which would 405). Confirmation lasts `auth.password_timeout`.
 */
class RequireRecentPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.privileged_reauth')) {
            return $next($request);
        }

        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if (time() - $confirmedAt <= (int) config('auth.password_timeout', 10800)) {
            return $next($request);
        }

        $request->session()->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : url()->previous());

        return redirect()->route('password.confirm');
    }
}
