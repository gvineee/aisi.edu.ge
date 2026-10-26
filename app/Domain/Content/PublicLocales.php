<?php

namespace App\Domain\Content;

use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

/**
 * The public site's languages. The school's own locale lives at the root
 * (`/about`); every other language under its prefix (`/en/about`). A
 * language appears in the switcher once at least one page is published in
 * it — the site never offers a language it has nothing to show in. A page
 * keeps one slug across languages, so its translations are found by slug.
 *
 * Untranslated pages are not faked: the switcher sends a visitor to that
 * language's start page instead, and a translated page links back to the
 * original language.
 */
class PublicLocales
{
    /** Prefixes the router accepts besides the school's own locale. */
    public const PREFIXED = ['en', 'ru'];

    public const LABELS = ['ka' => 'ქართ', 'en' => 'EN', 'ru' => 'RU'];

    /** @var array<int, list<string>> */
    private array $available = [];

    /**
     * @return list<string> the default locale first
     */
    public function available(Tenant $tenant): array
    {
        return $this->available[$tenant->id] ??= array_values(array_unique([
            $tenant->locale,
            ...Page::query()->where('tenant_id', $tenant->id)->where('status', Page::STATUS_PUBLISHED)->whereNotNull('published_at')
                ->whereIn('locale', self::PREFIXED)->distinct()->orderBy('locale')->pluck('locale')->all(),
        ]));
    }

    public function isSecondary(Tenant $tenant, string $locale): bool
    {
        return $locale !== $tenant->locale && in_array($locale, $this->available($tenant), true);
    }

    public function url(Tenant $tenant, string $locale, string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return $locale === $tenant->locale ? $path : '/'.$locale.($path === '/' ? '' : $path);
    }

    /**
     * The current request's locale: the route's `{locale}` prefix, else the
     * school's own.
     */
    public function current(Request $request, Tenant $tenant): string
    {
        $locale = $request->route('locale');

        return is_string($locale) && $this->isSecondary($tenant, $locale) ? $locale : $tenant->locale;
    }

    /**
     * The switcher for the current public page: each language links to this
     * same page in that language when it exists, else to its start page.
     *
     * @return list<array{code: string, label: string, url: string, current: bool, sameContent: bool}>
     */
    public function switcher(Request $request, Tenant $tenant): array
    {
        $locales = $this->available($tenant);

        if (count($locales) < 2) {
            return [];
        }

        $current = $this->current($request, $tenant);
        [$type, $slug] = $this->target($request);

        return array_map(function (string $locale) use ($tenant, $current, $type, $slug) {
            $path = match ($type) {
                'page' => $this->hasPage($tenant, $slug, $locale) ? ($slug === 'home' ? '/' : '/'.$slug) : null,
                'post' => $this->hasPost($tenant, $slug, $locale) ? '/news/'.$slug : null,
                'news' => $this->hasAnyPost($tenant, $locale) ? '/news' : null,
                default => null,
            };

            return [
                'code' => $locale,
                'label' => self::LABELS[$locale] ?? strtoupper($locale),
                'url' => $this->url($tenant, $locale, $path ?? '/'),
                'current' => $locale === $current,
                'sameContent' => $path !== null,
            ];
        }, $locales);
    }

    /**
     * The menu of a secondary language: its own pages (it has no section
     * hubs of its own) and its news, if any. Null for the school's locale,
     * whose menu is the full site's.
     *
     * @return list<array{href: string, label: string}>|null
     */
    public function nav(Tenant $tenant, string $locale): ?array
    {
        if ($locale === $tenant->locale) {
            return null;
        }

        $items = Page::query()->where('tenant_id', $tenant->id)->where('locale', $locale)->where('slug', '!=', 'home')
            ->where('status', Page::STATUS_PUBLISHED)->whereNotNull('published_at')->orderBy('title')->get(['slug', 'title'])
            ->map(fn (Page $page) => ['href' => $this->url($tenant, $locale, '/'.$page->slug), 'label' => $page->title])
            ->all();
        $items = array_values($items);

        if ($this->hasAnyPost($tenant, $locale)) {
            $items[] = ['href' => $this->url($tenant, $locale, '/news'), 'label' => $locale === 'ru' ? 'Новости' : 'News'];
        }

        return $items;
    }

    /**
     * hreflang alternates for the current page: only languages that really
     * have it.
     *
     * @return array<string, string> locale → path
     */
    public function alternates(Request $request, Tenant $tenant): array
    {
        return collect($this->switcher($request, $tenant))
            ->filter(fn (array $item) => $item['sameContent'])
            ->mapWithKeys(fn (array $item) => [$item['code'] => $item['url']])
            ->all();
    }

    /**
     * What the request shows: a page (by slug), a news post, or the news list.
     *
     * @return array{0: string|null, 1: string}
     */
    private function target(Request $request): array
    {
        $name = (string) $request->route()?->getName();
        $slug = (string) ($request->route('slug') ?? '');

        return match (true) {
            in_array($name, ['home', 'locale.home'], true) => ['page', 'home'],
            in_array($name, ['page.show', 'locale.page.show'], true) => ['page', $slug],
            in_array($name, ['news.show', 'locale.news.show'], true) => ['post', $slug],
            in_array($name, ['news.index', 'locale.news.index'], true) => ['news', ''],
            default => [null, ''],
        };
    }

    private function hasPage(Tenant $tenant, string $slug, string $locale): bool
    {
        return Page::query()->where('tenant_id', $tenant->id)->where('slug', $slug)->where('locale', $locale)
            ->where('status', Page::STATUS_PUBLISHED)->whereNotNull('published_at')->exists();
    }

    private function hasPost(Tenant $tenant, string $slug, string $locale): bool
    {
        return Post::query()->where('tenant_id', $tenant->id)->where('slug', $slug)->where('locale', $locale)
            ->where('status', Post::STATUS_PUBLISHED)->whereNotNull('published_at')->exists();
    }

    private function hasAnyPost(Tenant $tenant, string $locale): bool
    {
        return Post::query()->where('tenant_id', $tenant->id)->where('locale', $locale)
            ->where('status', Post::STATUS_PUBLISHED)->whereNotNull('published_at')->exists();
    }
}
