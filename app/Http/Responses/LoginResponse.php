<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's redirect to the page the visitor was on before signing in,
 * remembering for one request which page that was. If the account that
 * signed in may not open it (a parent's stale CMS form, then an operator
 * signs in), the 403 there becomes a move to the account's own space
 * instead of an error screen (recheck R03). Access checks are unchanged.
 */
class LoginResponse implements LoginResponseContract
{
    public const LANDING = 'auth.landing';

    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return $this->json();
        }

        $intended = $request->session()->get('url.intended');

        if (is_string($intended)) {
            $request->session()->flash(self::LANDING, $intended);
        }

        return redirect()->intended(Fortify::redirects('login'));
    }

    protected function json(): Response
    {
        return response()->json(['two_factor' => false]);
    }
}
