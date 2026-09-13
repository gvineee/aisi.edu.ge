<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\Teacher;
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
            'latestPosts' => $this->latestPostsIfNeeded($tenant->id, $page->blocks),
            'featuredTeachers' => $this->featuredTeachersIfNeeded($tenant->id, $page->blocks),
        ]);
    }

    /**
     * The "life" block renders real news teasers, so only query for posts
     * when a page actually has one — most pages don't.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function latestPostsIfNeeded(int $tenantId, array $blocks): array
    {
        $hasLifeBlock = collect($blocks)->contains(fn (array $block) => ($block['type'] ?? null) === 'life');

        if (! $hasLifeBlock) {
            return [];
        }

        return Post::query()
            ->where('tenant_id', $tenantId)
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->limit(6)
            ->get(['slug', 'title', 'excerpt', 'published_at'])
            ->map(fn (Post $post) => [
                'slug' => $post->slug,
                'title' => $post->title,
                'excerpt' => $post->excerpt,
                'publishedAt' => $post->published_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function featuredTeachersIfNeeded(int $tenantId, array $blocks): array
    {
        $hasTeachersBlock = collect($blocks)->contains(fn (array $block) => ($block['type'] ?? null) === 'teachers');

        if (! $hasTeachersBlock) {
            return [];
        }

        return Teacher::query()
            ->where('tenant_id', $tenantId)
            ->where('status', Teacher::STATUS_PUBLISHED)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Teacher $teacher) => [
                'slug' => $teacher->slug,
                'name' => $teacher->name,
                'subject' => $teacher->subject,
                'photoUrl' => $teacher->photoUrl(),
            ])
            ->values()
            ->all();
    }
}
