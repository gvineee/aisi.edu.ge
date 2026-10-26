<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\Teacher;
use App\Domain\Content\PublicLocales;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Generated from the current tenant's own published pages/posts — never a
 * static file, so unpublishing something stops advertising it immediately
 * and no other tenant's URLs can leak in here (docs/02 critical check #11).
 */
class SitemapController extends Controller
{
    public function __invoke(CurrentTenant $currentTenant, PublicLocales $locales): Response
    {
        $tenant = $currentTenant->get();

        $languages = [$tenant->locale, ...PublicLocales::PREFIXED];

        $pages = Page::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('locale', $languages)
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->orderBy('slug')
            ->get(['slug', 'locale', 'updated_at']);

        // Every language's own URL: the school's locale at the root, others under /{locale}.
        $pageUrls = $pages->map(fn (Page $page) => [
            'loc' => $tenant->canonicalUrl($locales->url($tenant, $page->locale, $page->slug === 'home' ? '/' : '/'.$page->slug)),
            'lastmod' => $page->updated_at?->toAtomString(),
        ]);

        $posts = Post::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('locale', $languages)
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->orderBy('slug')
            ->get(['slug', 'locale', 'updated_at']);

        $postUrls = $posts->map(fn (Post $post) => [
            'loc' => $tenant->canonicalUrl($locales->url($tenant, $post->locale, '/news/'.$post->slug)),
            'lastmod' => $post->updated_at?->toAtomString(),
        ]);

        $teachers = Teacher::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', Teacher::STATUS_PUBLISHED)
            ->orderBy('slug')
            ->get(['slug', 'updated_at']);

        $teacherUrls = $teachers->map(fn (Teacher $teacher) => [
            'loc' => $tenant->canonicalUrl('/teachers/'.$teacher->slug),
            'lastmod' => $teacher->updated_at?->toAtomString(),
        ]);

        $urls = $pageUrls
            ->concat($postUrls)
            ->push(['loc' => $tenant->canonicalUrl('/news'), 'lastmod' => null])
            ->push(['loc' => $tenant->canonicalUrl('/library'), 'lastmod' => null])
            ->push(['loc' => $tenant->canonicalUrl('/teachers'), 'lastmod' => null])
            ->concat($teacherUrls);

        // The XML declaration is prepended here rather than living in the
        // Blade view: production runs with short_open_tag=On, where PHP lexes
        // a literal "<?xml" in a template as a PHP open tag, so Blade leaves
        // that region uncompiled and the view dies with a syntax error.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}
