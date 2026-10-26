<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\CategoryListing;
use App\Domain\Content\Models\ContentMediaLink;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PublicLocales;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PostController extends Controller
{
    public function index(CurrentTenant $currentTenant, CategoryListing $categories): Response
    {
        return $this->list($currentTenant, $categories, $currentTenant->get()->locale);
    }

    public function localeIndex(CurrentTenant $currentTenant, CategoryListing $categories, PublicLocales $locales, string $locale): Response
    {
        abort_unless($locales->isSecondary($currentTenant->get(), $locale), 404);

        return $this->list($currentTenant, $categories, $locale);
    }

    public function localeShow(CurrentTenant $currentTenant, CategoryListing $categories, PublicLocales $locales, string $locale, string $slug): Response
    {
        abort_unless($locales->isSecondary($currentTenant->get(), $locale), 404);

        return $this->render($currentTenant, $categories, $slug, $locale);
    }

    private function list(CurrentTenant $currentTenant, CategoryListing $categories, string $locale): Response
    {
        $tenant = $currentTenant->get();

        // Posts placed in another section (e.g. documents) are listed there.
        $posts = Post::query()
            ->where('tenant_id', $tenant->id)
            ->where('locale', $locale)
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->whereNotIn('id', $categories->postIdsOutsideNews($tenant->id))
            ->orderByDesc('published_at')
            ->paginate(9)
            ->through(fn (Post $post) => [
                'slug' => $post->slug,
                'title' => $post->title,
                'excerpt' => $post->excerpt,
                'publishedAt' => $post->published_at?->toIso8601String(),
                'coverImageUrl' => $post->coverImageUrl(),
            ]);

        return Inertia::render('public/news-index', [
            'posts' => $posts,
        ]);
    }

    public function show(CurrentTenant $currentTenant, CategoryListing $categories, string $slug): Response
    {
        return $this->render($currentTenant, $categories, $slug, $currentTenant->get()->locale);
    }

    private function render(CurrentTenant $currentTenant, CategoryListing $categories, string $slug, string $locale): Response
    {
        $tenant = $currentTenant->get();

        $post = Post::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->first();

        if (! $post) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('public/news-show', [
            'post' => [
                'title' => $post->title,
                'excerpt' => $post->excerpt,
                'body' => $post->body,
                'publishedAt' => $post->published_at?->toIso8601String(),
                'seoTitle' => $post->seo_title,
                'seoDescription' => $post->seo_description,
                'coverImageUrl' => $post->coverImageUrl(),
            ],
            'breadcrumb' => $locale === $tenant->locale ? $categories->breadcrumbFor($post) : null,
            'media' => ContentMediaLink::propsFor($post),
        ]);
    }
}
