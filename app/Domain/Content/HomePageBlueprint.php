<?php

namespace App\Domain\Content;

/**
 * The home page's typed-block content, shared by TenantSeeder,
 * ProductionSeeder, and the `content:refresh-home` command so all three
 * stay in sync from one source instead of three hand-copied literals.
 *
 * Ported from design/app/AisiConcept.tsx section-by-section (per
 * docs/09-design-source-map.md's file map): hero, value props strip,
 * school history teaser, programs, portal promo, real news teasers
 * (rendered by resources/js/pages/public/page.tsx from live Post data,
 * not stored here), and the contact CTA.
 *
 * Copy is transferred verbatim from the design file per explicit user
 * instruction (2026-09-11) to use the GPT-approved/shared design text
 * exactly as written, rather than the more cautious placeholder this
 * blueprint previously carried — including the founding year and
 * founder name, which the design file states directly.
 */
class HomePageBlueprint
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function blocks(): array
    {
        return [
            [
                'type' => 'hero',
                'eyebrow' => 'სკოლა ახალი შესაძლებლობებისთვის',
                // Split into two lines with the last word emphasized in the
                // brand accent color, matching design/app/AisiConcept.tsx's
                // `აქ იწყება<br/>შენი <em>ხვალ.</em>` exactly.
                'heading' => 'აქ იწყება',
                'headingLine2' => 'შენი',
                'headingEmphasis' => 'ხვალ.',
                'body' => 'სივრცე, სადაც ცნობისმოყვარეობა ცოდნად იქცევა, ბავშვები კი საკუთარი გზის პოვნას სწავლობენ.',
                'note' => 'ცოდნა. მეგობრობა. ახალი შესაძლებლობები. ერთად, ყოველი ახალი დღიდან.',
                // design/public/school-life.jpg per docs/09-design-source-map.md:
                // "არქიტექტურული ვიზუალიზაცია; არა დადასტურებული დღევანდელი
                // ფოტო — გამოყენება/აქტუალობა სკოლამ უნდა დაადასტუროს."
                'imageAlt' => 'სკოლა აისის შენობის ვიზუალიზაცია',
                'imageCaption' => 'შენობის ვიზუალიზაცია · დასადასტურებელია სკოლის მიერ',
            ],
            [
                'type' => 'values',
                'items' => [
                    ['label' => 'ცოდნა, რომელიც გამოგადგება'],
                    ['label' => 'გარემო, სადაც გისმენენ'],
                    ['label' => 'შესაძლებლობა, იყო შენ'],
                ],
            ],
            [
                'type' => 'history',
                'eyebrow' => 'გაიცანი აისი',
                'heading' => 'სკოლა იწყება ბავშვის ინტერესით.',
                'lead' => 'კარგი განათლება კითხვების დასმის გამბედაობას გვაძლევს.',
                'body' => 'სკოლა „აისი" 2000 წელს თბილისში დაარსდა. მისი დამფუძნებელია პედაგოგიკის დოქტორი იანა ტორჩინავა. სკოლის მისია აერთიანებს განათლებას, პიროვნულ განვითარებასა და ოჯახის თანამშრომლობას.',
                'ctaLabel' => 'გაიცანი სკოლის ისტორია',
                'ctaHref' => '/about',
            ],
            [
                'type' => 'programs',
                'heading' => 'ყოველ ეტაპს — თავისი აღმოჩენა.',
                'items' => [
                    ['title' => 'პირველი ნაბიჯები', 'grade' => 'დაწყებითი საფეხური', 'body' => 'კითხვის სიხარული, პირველი აღმოჩენები და სწავლის სიყვარული.'],
                    ['title' => 'ინტერესების აღმოჩენა', 'grade' => 'საბაზო საფეხური', 'body' => 'კითხვებიდან იდეებამდე — მეტი დამოუკიდებლობა და თანამშრომლობა.'],
                    ['title' => 'საკუთარი გზა', 'grade' => 'საშუალო საფეხური', 'body' => 'გაცნობიერებული არჩევანი და მომავლისთვის მზადება.'],
                ],
            ],
            [
                'type' => 'portal_promo',
                'eyebrow' => 'პორტალი',
                'heading' => 'სკოლის დღე. ერთი შეხედვით.',
                'body' => 'განრიგი, სასწავლო მასალები და მნიშვნელოვანი ამბები — მშობლისა და მოსწავლის პირად სივრცეში.',
                'ctaLabel' => 'შედი პორტალში',
                'ctaHref' => '/login',
            ],
            [
                'type' => 'life',
                'eyebrow' => 'სასკოლო ცხოვრება',
                'heading' => 'დღეები, რომლებიც გვზრდის.',
                'ctaLabel' => 'ყველა ამბავი',
                'ctaHref' => '/news',
            ],
            [
                'type' => 'contact_cta',
                'eyebrow' => 'შემდეგი ნაბიჯი',
                'heading' => 'გავიცნოთ ერთმანეთი.',
                'body' => 'ვიზიტის დრო წინასწარ შეათანხმეთ სკოლასთან.',
            ],
        ];
    }
}
