<?php

namespace App\Domain\Content;

use App\Domain\Content\Models\ContentCategorizable;
use App\Domain\Content\Models\ContentCategory;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use Illuminate\Database\Eloquent\Model;

/**
 * Public side of the site categories: what a hub page lists, the
 * breadcrumb of a placed page/post, and which posts belong to a section
 * other than news. Only published items in public categories ever appear;
 * the archive category is never shown. Items keep their own URL, title and
 * original date — a category only groups them.
 */
class CategoryListing
{
    /**
     * Published items placed in the category whose hub is `$path`.
     *
     * @return array{name: string, items: list<array{title: string, url: string, date: string|null, excerpt: string|null, section: string|null}>}|null
     */
    public function forHub(int $tenantId, string $path, ?string $excludeUrl = null): ?array
    {
        $category = ContentCategory::query()
            ->where('tenant_id', $tenantId)
            ->where('hub_path', $path)
            ->where('is_public', true)
            ->first();

        if ($category === null) {
            return null;
        }

        $items = array_values(ContentCategorizable::query()
            ->where('tenant_id', $tenantId)
            ->where('content_category_id', $category->id)
            ->with('categorizable')
            ->orderBy('section')
            ->orderBy('position')
            ->get()
            ->map(fn (ContentCategorizable $placement) => $this->item($placement))
            ->filter(fn (?array $item) => $item !== null && $item['url'] !== $excludeUrl)
            ->all());

        return $items === [] ? null : ['name' => $category->name, 'items' => $items];
    }

    /**
     * The category hub a placed page/post belongs to, unless it is the hub.
     *
     * @return array{name: string, href: string}|null
     */
    public function breadcrumbFor(Page|Post $target): ?array
    {
        $placement = ContentCategorizable::query()
            ->where('categorizable_type', $target::class)
            ->where('categorizable_id', $target->id)
            ->with('category')
            ->first();

        $category = $placement?->category;

        if ($category === null || ! $category->is_public || $category->hub_path === null || $category->hub_path === $this->url($target)) {
            return null;
        }

        return ['name' => $category->name, 'href' => $category->hub_path];
    }

    /**
     * Posts placed in a public section other than news (e.g. a governance
     * document that was published as a post). They keep their /news/ URL
     * but are listed under their own section, not twice.
     *
     * @return list<int>
     */
    public function postIdsOutsideNews(int $tenantId): array
    {
        return array_values(ContentCategorizable::query()
            ->where('tenant_id', $tenantId)
            ->where('categorizable_type', Post::class)
            ->whereHas('category', fn ($query) => $query->where('is_public', true)->where('slug', '!=', 'news'))
            ->pluck('categorizable_id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    /**
     * @return array{title: string, url: string, date: string|null, excerpt: string|null, section: string|null}|null
     */
    private function item(ContentCategorizable $placement): ?array
    {
        $target = $placement->categorizable;

        if ((! $target instanceof Page && ! $target instanceof Post) || $target->tenant_id !== $placement->tenant_id || ! $this->isLive($target)) {
            return null;
        }

        return [
            'title' => $target->title,
            'url' => $this->url($target),
            'date' => $target->published_at?->toIso8601String(),
            'excerpt' => $target->excerpt,
            'section' => $placement->section,
        ];
    }

    private function isLive(Model $target): bool
    {
        return $target->getAttribute('status') === Page::STATUS_PUBLISHED && $target->getAttribute('published_at') !== null;
    }

    private function url(Page|Post $target): string
    {
        if ($target instanceof Post) {
            return '/news/'.$target->slug;
        }

        return $target->slug === 'home' ? '/' : '/'.$target->slug;
    }
}
