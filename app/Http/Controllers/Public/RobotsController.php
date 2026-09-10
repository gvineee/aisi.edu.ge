<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        // NOTE: once the school portal (Phase 2) gets real routes, they must
        // be added here as Disallow rules — private/authenticated areas
        // should never rely on robots.txt alone (it's not access control),
        // but they also should not be advertised for indexing.
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Sitemap: '.url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines)."\n", 200)
            ->header('Content-Type', 'text/plain');
    }
}
