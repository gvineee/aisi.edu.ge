<?php

namespace App\Domain\Content;

/**
 * The first school's public menu: the hierarchy of its previous site's
 * main menu (content-migration/raw/pages/001355f94f4ea278bb.html,
 * `menu-main-menu`), as the user asked on 2026-11-01. Each legacy page is
 * listed by the slug it was imported under, then the canonical page that
 * covers the same subject, so the entry follows whichever the school has
 * published (PublicNavigation drops it while neither is).
 *
 * Deliberate differences from the legacy menu:
 * - the duplicated „შიდა რეგულაციები“ appears once;
 * - „ელექტრონილი ჟურნალი“ is spelled correctly;
 * - the legacy „ენა:“ group is not stored: the header builds it from the
 *   languages the site really has (PublicLocales);
 * - „ადმინისტრაცია“ pointed at /events/, which showed „რატომ ჩვენი
 *   სკოლა?“: the old site had no administration page, so the entry stays
 *   hidden until the school publishes one under the `administration` slug;
 * - the history entry never falls back to import-wp-pages-1241, the old
 *   theme's lorem-ipsum template.
 *
 * `utility` is the old site's „სასარგებლო ბმულები“ menu (top bar and
 * footer) in its order, followed by news and admissions, which the old
 * menu reached through posts. LegacyMenuPages lists the imported pages
 * these entries need and `content:publish-menu-pages` publishes them.
 * Written to a tenant by `content:refresh-navigation`.
 */
class NavigationBlueprint
{
    /**
     * @return array{main: list<array<string, mixed>>, utility: list<array<string, mixed>>, tagline: string}
     */
    public static function definition(): array
    {
        return [
            'tagline' => 'აქ იწყება შენი ხვალ.',
            'main' => [
                ['label' => 'სკოლა', 'children' => [
                    ['label' => 'აკრედიტაცია', 'children' => [
                        ['label' => 'დოკუმენტები', 'pages' => ['official-documents']],
                        ['label' => 'ონლაინ ბიბლიოთეკა', 'path' => '/library'],
                        ['label' => 'სკოლის მისია', 'pages' => ['import-wp-pages-1252', 'about']],
                        ['label' => 'შიდა რეგულაციები', 'pages' => ['import-wp-pages-1205']],
                        ['label' => 'სკოლის ისტორია', 'pages' => ['import-wp-pages-2371']],
                        ['label' => 'დირექტორის მისალმება', 'pages' => ['import-wp-pages-1225']],
                        ['label' => 'ელექტრონული ჟურნალი', 'url' => 'https://aisi.edupage.org/'],
                    ]],
                ]],
                ['label' => 'სასწავლო პროცესი', 'children' => [
                    ['label' => 'ავტორიზაცია', 'pages' => ['import-wp-pages-2337']],
                    ['label' => 'ჩარიცხვის წესი', 'pages' => ['import-wp-pages-2313', 'admissions']],
                ]],
                ['label' => 'ჩვენი გუნდი', 'children' => [
                    ['label' => 'მასწავლებლები', 'path' => '/teachers'],
                    ['label' => 'ადმინისტრაცია', 'pages' => ['administration']],
                ]],
                ['label' => 'პროექტები', 'pages' => ['school-life']],
            ],
            'utility' => [
                ['label' => 'ჩარიცხვის წესი', 'pages' => ['import-wp-pages-2313', 'admissions']],
                ['label' => 'მასწავლებლები', 'path' => '/teachers'],
                ['label' => 'კონტაქტი', 'pages' => ['contact', 'import-wp-pages-1111']],
                ['label' => 'რატომ აისი?', 'pages' => ['import-wp-pages-2347', 'import-wp-pages-871']],
                ['label' => 'სიახლეები', 'path' => '/news'],
                ['label' => 'მიღება', 'pages' => ['admissions']],
            ],
        ];
    }
}
