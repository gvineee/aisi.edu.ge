<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HomeController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();

        // BelongsToTenant's global scope already filters by the resolved
        // tenant, but a public content lookup is exactly the kind of path
        // CLAUDE.md calls out for a defensive, explicit tenant_id check too.
        $page = Page::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', 'home')
            ->where('locale', $tenant->locale)
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->first();

        if (! $page) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('public/home', [
            'page' => [
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'blocks' => $page->blocks,
                'seoTitle' => $page->seo_title,
                'seoDescription' => $page->seo_description,
            ],
        ]);
    }
}
