<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Models\PlatformOperator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform administration is only for active platform operators with
 * confirmed 2FA (CLAUDE.md #4). Tenant roles — even a school's admin —
 * never grant it. Returns 404 so the area is not advertised.
 */
class EnsurePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $isOperator = $user !== null
            && $user->two_factor_confirmed_at !== null
            && PlatformOperator::query()->where('user_id', $user->id)->where('is_active', true)->exists();

        abort_unless($isOperator, 404);

        return $next($request);
    }
}
