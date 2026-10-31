<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:5,1` with each route counted on its own.
 *
 * Laravel keys an unnamed limit by user (or domain + IP) only, so every
 * `throttle:X,Y` route shared one counter: opening the visit dialog five
 * times (each load of the free times is a throttled GET) used up the
 * 5-per-minute budget of the visit form itself, and a teacher's ordinary
 * clicks spent the timetable generator's allowance. The route is now part
 * of the key; named limiters (`throttle:login`) are unaffected.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $routeKey = $route === null ? $request->path() : ($route->getName() ?? implode('|', $route->methods()).' '.$route->uri());

        return sha1(parent::resolveRequestSignature($request).'|'.$routeKey);
    }
}
