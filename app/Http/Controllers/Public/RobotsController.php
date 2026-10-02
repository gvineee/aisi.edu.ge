<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        // Private/authenticated and token-bearing areas are not advertised
        // for crawling. robots.txt is not access control — every one of
        // these routes still authenticates, and NoIndexPrivateAreas adds an
        // X-Robots-Tag header to their responses as well.
        $lines = ['User-agent: *'];

        foreach (['/portal/', '/dashboard', '/settings/', '/login', '/register', '/invitations/', '/verify/'] as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $lines[] = 'Allow: /';
        $lines[] = 'Sitemap: '.url('/sitemap.xml');

        return response(implode("\n", $lines)."\n", 200)
            ->header('Content-Type', 'text/plain');
    }
}
