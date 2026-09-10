<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Generated from the current tenant's own published pages — never a static
 * file, so a page that gets unpublished stops being advertised immediately
 * and no other tenant's URLs can leak in here (docs/02 critical check #11).
 */
class SitemapController extends Controller
{
    public function __invoke(CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();

        $pages = Page::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->orderBy('slug')
            ->get(['slug', 'locale', 'updated_at']);

        $urls = $pages->map(function (Page $page) {
            $loc = $page->slug === 'home' ? url('/') : url('/'.$page->slug);

            return [
                'loc' => $loc,
                'lastmod' => $page->updated_at?->toAtomString(),
            ];
        });

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}
