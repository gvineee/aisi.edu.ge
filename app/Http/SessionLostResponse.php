<?php

namespace App\Http;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * What an Inertia write gets when its session is gone (signed out in
 * another tab, expired, stale CSRF token): a full-page visit (409 +
 * X-Inertia-Location), which never fires the form's onSuccess, and a plain
 * note that nothing was saved. GET visits and non-Inertia requests keep
 * Laravel's usual handling.
 */
class SessionLostResponse
{
    public const MESSAGE = 'სესია დასრულდა, ამიტომ ბოლო მოქმედება არ შენახულა. შედით ხელახლა და გაიმეორეთ — ფაილი, თუ ტვირთავდით, თავიდან აირჩიეთ.';

    public const PUBLIC_MESSAGE = 'გვერდი დიდხანს იყო ღია და ფორმა ვეღარ გაიგზავნა. შეავსეთ და გაგზავნეთ თავიდან.';

    public static function for(Request $request): ?Response
    {
        if (! $request->header('X-Inertia') || $request->isMethod('GET') || ! $request->hasSession()) {
            return null;
        }

        $session = $request->session();
        $back = url()->previous(route('home'));
        $behindLogin = collect($request->route()?->gatherMiddleware() ?? [])
            ->contains(fn ($middleware) => is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:')));

        if ($request->user() !== null || ! $behindLogin) {
            // Still signed in (only the token was stale) or a public form:
            // reload the page the form was on, with the note.
            $session->flash('toast', ['type' => 'error', 'message' => $behindLogin ? self::MESSAGE : self::PUBLIC_MESSAGE]);

            return Inertia::location($back);
        }

        // After signing in, back to the page the form was on, not to the
        // write's own URL. The sign-in page shows the note as its status.
        $session->put('url.intended', $back);
        $session->flash('status', self::MESSAGE);

        return Inertia::location(route('login'));
    }
}
