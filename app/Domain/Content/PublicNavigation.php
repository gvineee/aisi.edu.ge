<?php

namespace App\Domain\Content;

use App\Domain\Content\Models\Page;
use App\Domain\Tenancy\Models\Tenant;

/**
 * The public site's menu for the school's own language: a main hierarchy
 * (header, desktop dropdowns / mobile accordion) and auxiliary links
 * (footer, foot of the mobile menu).
 *
 * The structure is the school's content, kept in `brand_settings.navigation`
 * (see NavigationBlueprint for the shape); a school without one gets the
 * generic menu below. A menu entry never promises a page the visitor
 * cannot open: a `pages` entry links to the first of its candidate slugs
 * that is published, and is left out when none is. A group whose entries
 * are all left out disappears too.
 *
 * Entry shapes:
 *   ['label' => '…', 'pages' => ['slug', 'fallback-slug']]  CMS page
 *   ['label' => '…', 'path' => '/news']                      own route
 *   ['label' => '…', 'url' => 'https://…']                   external
 *   ['label' => '…', 'children' => [ …entries… ]]            group
 *
 * `tagline` (optional) is the footer's one-line sign-off.
 */
class PublicNavigation
{
    private const MAX_DEPTH = 3;

    /** @var array<int, array{main: list<array<string, mixed>>, utility: list<array<string, mixed>>, tagline: string|null}> */
    private array $resolved = [];

    /**
     * @return array{main: list<array<string, mixed>>, utility: list<array<string, mixed>>, tagline: string|null}
     */
    public function for(Tenant $tenant): array
    {
        if (isset($this->resolved[$tenant->id])) {
            return $this->resolved[$tenant->id];
        }

        $definition = $tenant->brandSetting?->navigation;

        if (! is_array($definition) || ! is_array($definition['main'] ?? null)) {
            $definition = self::defaultDefinition();
        }

        $main = array_values(array_filter($definition['main'], 'is_array'));
        $utility = array_values(array_filter(is_array($definition['utility'] ?? null) ? $definition['utility'] : [], 'is_array'));

        $published = array_flip(Page::query()
            ->where('tenant_id', $tenant->id)
            ->where('locale', $tenant->locale)
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->whereIn('slug', $this->candidateSlugs([...$main, ...$utility]))
            ->pluck('slug')
            ->all());

        return $this->resolved[$tenant->id] = [
            'main' => $this->resolve($main, $published, 0),
            'utility' => $this->resolve($utility, $published, self::MAX_DEPTH),
            'tagline' => is_string($definition['tagline'] ?? null) && trim($definition['tagline']) !== '' ? trim($definition['tagline']) : null,
        ];
    }

    /**
     * The menu of a school that has not set its own: the standard pages.
     *
     * @return array{main: list<array<string, mixed>>, utility: list<array<string, mixed>>}
     */
    public static function defaultDefinition(): array
    {
        return [
            'main' => [
                ['label' => 'სკოლის შესახებ', 'pages' => ['about']],
                ['label' => 'სწავლა', 'pages' => ['learning']],
                ['label' => 'სასკოლო ცხოვრება', 'pages' => ['school-life']],
                ['label' => 'ამბები', 'path' => '/news'],
                ['label' => 'ბიბლიოთეკა', 'path' => '/library'],
                ['label' => 'კონტაქტი', 'pages' => ['contact']],
            ],
            'utility' => [
                ['label' => 'მიღება', 'pages' => ['admissions']],
                ['label' => 'დოკუმენტები', 'pages' => ['official-documents']],
                ['label' => 'კონტაქტი', 'pages' => ['contact']],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, int>  $published
     * @return list<array<string, mixed>>
     */
    private function resolve(array $entries, array $published, int $depth): array
    {
        $items = [];

        foreach ($entries as $entry) {
            $label = is_string($entry['label'] ?? null) ? trim($entry['label']) : '';

            if ($label === '') {
                continue;
            }

            if (is_array($entry['children'] ?? null)) {
                if ($depth >= self::MAX_DEPTH) {
                    continue;
                }

                $children = $this->resolve(array_values(array_filter($entry['children'], 'is_array')), $published, $depth + 1);

                if ($children !== []) {
                    $items[] = ['label' => $label, 'children' => $children];
                }

                continue;
            }

            $link = $this->link($entry, $published);

            if ($link !== null) {
                $items[] = ['label' => $label, ...$link];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, int>  $published
     * @return array{href: string, external: bool}|null
     */
    private function link(array $entry, array $published): ?array
    {
        if (is_array($entry['pages'] ?? null)) {
            foreach ($entry['pages'] as $slug) {
                if (is_string($slug) && isset($published[$slug])) {
                    return ['href' => $slug === 'home' ? '/' : '/'.$slug, 'external' => false];
                }
            }

            return null;
        }

        $path = $entry['path'] ?? null;

        if (is_string($path) && preg_match('~^/(?!/)[^\s<>"\\\\]*$~', $path) === 1) {
            return ['href' => $path, 'external' => false];
        }

        $url = $entry['url'] ?? null;

        if (is_string($url) && preg_match('~^https://~i', $url) === 1 && PageBlockRules::isSafeLink($url)) {
            return ['href' => $url, 'external' => true];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<string>
     */
    private function candidateSlugs(array $entries): array
    {
        $slugs = [];

        foreach ($entries as $entry) {
            if (is_array($entry['children'] ?? null)) {
                $slugs = [...$slugs, ...$this->candidateSlugs(array_values(array_filter($entry['children'], 'is_array')))];
            }

            if (is_array($entry['pages'] ?? null)) {
                $slugs = [...$slugs, ...array_filter($entry['pages'], 'is_string')];
            }
        }

        return array_values(array_unique($slugs));
    }
}
