<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders any of the tenant's own published CMS pages (home included) from
 * a single Inertia component — every page shares the same typed-block
 * structure (hero/programs/life/contact_cta/text), so there is one
 * rendering path to keep correct rather than one per page.
 */
class PageController extends Controller
{
    public function home(CurrentTenant $currentTenant): Response
    {
        return $this->render($currentTenant, 'home');
    }

    public function show(Request $request, CurrentTenant $currentTenant, string $slug): Response
    {
        if ($slug === 'home') {
            // Canonical URL for the home page is "/", not "/home".
            throw new NotFoundHttpException;
        }

        return $this->render($currentTenant, $slug);
    }

    private function render(CurrentTenant $currentTenant, string $slug): Response
    {
        $tenant = $currentTenant->get();

        $page = Page::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->where('locale', $tenant->locale)
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->first();

        if (! $page) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('public/page', [
            'page' => [
                'slug' => $page->slug,
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'blocks' => $page->blocks,
                'seoTitle' => $page->seo_title,
                'seoDescription' => $page->seo_description,
            ],
        ]);
    }
}
