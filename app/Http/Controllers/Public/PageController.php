<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\CategoryListing;
use App\Domain\Content\Models\ContentMediaLink;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\Teacher;
use App\Domain\Content\PublicLocales;
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
        return $this->render($currentTenant, 'home', $currentTenant->get()->locale);
    }

    public function show(Request $request, CurrentTenant $currentTenant, string $slug): Response
    {
        if ($slug === 'home') {
            // Canonical URL for the home page is "/", not "/home".
            throw new NotFoundHttpException;
        }

        return $this->render($currentTenant, $slug, $currentTenant->get()->locale);
    }

    /**
     * `/en`, `/ru`: that language's own home page if the school wrote one,
     * else an index of everything published in it.
     */
    public function localeHome(CurrentTenant $currentTenant, PublicLocales $locales, string $locale): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($locales->isSecondary($tenant, $locale), 404);

        if ($this->published($tenant->id, 'home', $locale) !== null) {
            return $this->render($currentTenant, 'home', $locale);
        }

        return Inertia::render('public/locale-home', [
            'locale' => $locale,
            'pages' => Page::query()->where('tenant_id', $tenant->id)->where('locale', $locale)
                ->where('status', Page::STATUS_PUBLISHED)->whereNotNull('published_at')->orderBy('title')->get(['slug', 'title', 'excerpt'])
                ->map(fn (Page $page) => ['url' => $locales->url($tenant, $locale, '/'.$page->slug), 'title' => $page->title, 'excerpt' => $page->excerpt])->values(),
            'posts' => Post::query()->where('tenant_id', $tenant->id)->where('locale', $locale)
                ->where('status', Post::STATUS_PUBLISHED)->whereNotNull('published_at')->orderByDesc('published_at')->limit(6)->get(['slug', 'title', 'excerpt', 'published_at'])
                ->map(fn (Post $post) => ['url' => $locales->url($tenant, $locale, '/news/'.$post->slug), 'title' => $post->title, 'excerpt' => $post->excerpt, 'publishedAt' => $post->published_at?->toIso8601String()])->values(),
        ]);
    }

    public function localeShow(CurrentTenant $currentTenant, PublicLocales $locales, string $locale, string $slug): Response
    {
        abort_unless($locales->isSecondary($currentTenant->get(), $locale) && $slug !== 'home', 404);

        return $this->render($currentTenant, $slug, $locale);
    }

    private function published(int $tenantId, string $slug, string $locale): ?Page
    {
        return Page::query()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->first();
    }

    private function render(CurrentTenant $currentTenant, string $slug, string $locale): Response
    {
        $tenant = $currentTenant->get();
        $categories = app(CategoryListing::class);
        $url = $slug === 'home' ? '/' : '/'.$slug;
        $page = $this->published($tenant->id, $slug, $locale);

        if (! $page) {
            throw new NotFoundHttpException;
        }

        // Sections, teacher cards and breadcrumbs belong to the school's own-language site.
        $isDefault = $locale === $tenant->locale;

        return Inertia::render('public/page', [
            'page' => [
                'slug' => $page->slug,
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'blocks' => $page->blocks,
                'seoTitle' => $page->seo_title,
                'seoDescription' => $page->seo_description,
            ],
            'latestPosts' => $this->latestPostsIfNeeded($tenant->id, $page->blocks, $locale),
            'featuredTeachers' => $isDefault ? $this->featuredTeachersIfNeeded($tenant->id, $page->blocks) : [],
            'categorySection' => $isDefault ? $categories->forHub($tenant->id, $url, $url) : null,
            'breadcrumb' => $isDefault ? $categories->breadcrumbFor($page) : null,
            'media' => ContentMediaLink::propsFor($page),
        ]);
    }

    /**
     * The "life" block renders real news teasers, so only query for posts
     * when a page actually has one — most pages don't.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function latestPostsIfNeeded(int $tenantId, array $blocks, string $locale): array
    {
        $hasLifeBlock = collect($blocks)->contains(fn (array $block) => ($block['type'] ?? null) === 'life');

        if (! $hasLifeBlock) {
            return [];
        }

        return Post::query()
            ->where('tenant_id', $tenantId)
            ->where('locale', $locale)
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->limit(6)
            ->get(['slug', 'title', 'excerpt', 'published_at', 'cover_image_path'])
            ->map(fn (Post $post) => [
                'slug' => $post->slug,
                'title' => $post->title,
                'excerpt' => $post->excerpt,
                'publishedAt' => $post->published_at?->toIso8601String(),
                'coverImageUrl' => $post->coverImageUrl(),
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
