<?php

namespace App\Domain\Content;

/**
 * The previous site's menu pages that NavigationBlueprint links to, and how
 * each imported draft is presented once published (2026-11-02: the user
 * asked for the menu to be filled the way the old site had it).
 *
 * The text is the school's own, imported verbatim from its previous site
 * (content-migration/rendered-pages.json); this class only gives each page
 * the new layout: a page hero with the menu group as eyebrow, and the body
 * without the import's repeated title and doubled spaces.
 *
 * Deliberately not here:
 * - import-wp-pages-1241 is the old theme's lorem-ipsum template, never the
 *   school's history;
 * - import-wp-pages-871 duplicates 2347 („რატომ აისი“);
 * - import-wp-pages-1111 duplicates the canonical contact page;
 * - „ადმინისტრაცია“: the old link opened „რატომ ჩვენი სკოლა?“ (/events/),
 *   so the old site never had an administration page to restore.
 */
class LegacyMenuPages
{
    /**
     * Slug => eyebrow (the old menu group the page sat under).
     *
     * @var array<string, string>
     */
    public const PAGES = [
        'import-wp-pages-1252' => 'სკოლა · აკრედიტაცია',
        'import-wp-pages-1205' => 'სკოლა · აკრედიტაცია',
        'import-wp-pages-2371' => 'სკოლა · აკრედიტაცია',
        'import-wp-pages-1225' => 'სკოლა · აკრედიტაცია',
        'import-wp-pages-2337' => 'სასწავლო პროცესი',
        'import-wp-pages-2313' => 'სასწავლო პროცესი',
        'import-wp-pages-2347' => 'სკოლა',
    ];

    /** The page whose imported text lost its PDF links. */
    public const REGULATIONS = 'import-wp-pages-1205';

    /**
     * The blocks a menu page is published with, built from its imported
     * blocks. Returns null when the imported page has no usable text.
     *
     * @param  array<int, array<string, mixed>>  $imported
     * @return array<int, array<string, mixed>>|null
     */
    public static function blocks(string $slug, string $title, array $imported): ?array
    {
        $hero = ['type' => 'hero', 'eyebrow' => self::PAGES[$slug], 'heading' => $title];

        // The old page listed these regulations as PDF links; the import kept
        // only their captions (most of them wrong). The documents page already
        // holds the same real PDFs, so the regulations page shows that list.
        if ($slug === self::REGULATIONS) {
            $group = self::regulationsGroup();

            return $group === null ? null : [$hero, ['type' => 'documents', 'groups' => [$group]]];
        }

        $paragraphs = [];

        foreach ($imported as $block) {
            if (($block['type'] ?? null) !== 'text') {
                continue;
            }

            $body = $block['body'] ?? '';
            $parts = is_array($body) ? $body : preg_split('/\n\s*\n/u', (string) $body);

            foreach ($parts ?: [] as $part) {
                $part = trim((string) preg_replace('/[ \t\x{00A0}]{2,}/u', ' ', (string) $part));

                // The import repeats the page title as the first paragraph.
                if ($part === '' || ($paragraphs === [] && mb_strtolower($part) === mb_strtolower(trim($title)))) {
                    continue;
                }

                $paragraphs[] = $part;
            }
        }

        if ($paragraphs === []) {
            return null;
        }

        return [$hero, ['type' => 'text', 'body' => $paragraphs]];
    }

    /**
     * @return array{heading: string, items: list<array{title: string, url: string}>}|null
     */
    private static function regulationsGroup(): ?array
    {
        foreach (DocumentsPageBlueprint::blocks() as $block) {
            foreach ($block['groups'] ?? [] as $group) {
                if (str_starts_with($group['heading'], 'შიდა რეგულაციები')) {
                    return $group;
                }
            }
        }

        return null;
    }
}
