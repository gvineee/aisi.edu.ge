<?php

namespace App\Domain\Content;

/**
 * The home page's typed-block content, shared by TenantSeeder,
 * ProductionSeeder, and the `content:refresh-home` command so all three
 * stay in sync from one source instead of three hand-copied literals.
 *
 * Ported section by section from the 2026-11-01 visual direction
 * (design/app/new-vision/NewVision.tsx, docs/aisi-new-vision-2026-11-01.md):
 * hero, values ribbon, school intro, "one day" discovery tabs, stages,
 * portal promo, real news (rendered by resources/js/pages/public/page.tsx
 * from live Post data, not stored here) and the visit invitation.
 *
 * The copy is the design's editorial proposal, kept editable in the CMS.
 * Nothing here is a new statistic, rating, testimonial or programme claim.
 * The hero photo is a generated concept illustration — it shows no real
 * pupils or school building, its alt text says so, and it should be
 * replaced by a rights-cleared school photo of the same composition. The
 * discovery-tab photos come from the school's own archive (publication
 * rights to be confirmed by the school).
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
                'heading' => 'დიდი ხვალ.',
                'headingLine2' => 'პატარა',
                'headingEmphasis' => 'აღმოჩენებით.',
                'body' => "ადგილი, სადაც „რატომ?“ კარგი დასაწყისია.\nდა ყველა ბავშვს თავისი აღმოჩენა ელოდება.",
                'ctaLabel' => 'გაიცანი შენი აისი',
                'secondaryLabel' => 'ერთი დღე აისში',
                'secondaryHref' => '#school-day',
                'note' => 'ცოდნა. მეგობრობა. საკუთარი გზა.',
                'noteEmphasis' => 'ერთად, ყოველი ახალი დღიდან.',
                'handNote' => "აქ კითხვებს\nფრთები ესხმება.",
                'photoNoteTitle' => '„ვიპოვე!“',
                'photoNoteBody' => 'აი, ამ მომენტისთვის.',
                'verticalNote' => 'პატარა აღმოჩენები. დიდი შესაძლებლობები.',
                'imageUrl' => '/images/home/discovery-concept.webp',
                'imageAlt' => 'კონცეფციის ილუსტრაცია: ბავშვები და მასწავლებელი ერთად აღმოჩენენ და ქმნიან',
            ],
            [
                'type' => 'values',
                'items' => [
                    ['label' => 'იფიქრე.'],
                    ['label' => 'სცადე.'],
                    ['label' => 'იპოვე.'],
                    ['label' => 'იყავი შენ.'],
                ],
            ],
            [
                'type' => 'history',
                'eyebrow' => 'გაიცანი აისი',
                'heading' => 'ბავშვობა არ მეორდება.',
                'headingAccent' => 'მივცეთ მას სივრცე.',
                'body' => 'კარგი სკოლა მხოლოდ პასუხებს არ გასწავლის. კითხვების დასმის გამბედაობას გაძლევს — და ადამიანებს, ვისთან ერთადაც გზის პოვნა უფრო საინტერესოა.',
                'ctaLabel' => 'ჩვენი ისტორია',
                'ctaHref' => '/about',
                'belief' => 'პატარა ნაბიჯებსაც დიდი მნიშვნელობა აქვს.',
                'beliefMeta' => 'აისი · 2000 წლიდან',
            ],
            [
                'type' => 'discoveries',
                'eyebrow' => 'ერთი დღე, ბევრი ამბავი',
                'heading' => 'სკოლა, რომელსაც',
                'headingAccent' => 'გაგრძელება აქვს.',
                'aside' => "გაკვეთილის შემდეგაც.\nზარის შემდეგაც.\nწლების შემდეგაც.",
                'ctaLabel' => 'გაიცანი სასკოლო ცხოვრება',
                'ctaHref' => '/school-life',
                'items' => [
                    [
                        'label' => 'ერთად ფიქრი',
                        'title' => "ერთი კითხვა.\nათასი შესაძლებლობა.",
                        'body' => 'ზოგჯერ ყველაზე საინტერესო გაკვეთილი ერთი „რატომ?“ იწყება. ერთად ვეძებთ, ვცდით და აღმოჩენის სიხარულს ერთმანეთს ვუზიარებთ.',
                        'tag' => 'ცნობისმოყვარეობა მოქმედებაში',
                        'imageUrl' => '/images/home/discovery-concept.webp',
                        'imageAlt' => 'კონცეფციის ილუსტრაცია: ბავშვები და მასწავლებელი ერთად ქმნიან პატარა მოდელს',
                    ],
                    [
                        'label' => 'ხელით შექმნა',
                        'title' => "იდეებს ფერები\nძალიან უხდება.",
                        'body' => 'როცა ხელს საღებავით ისვრი, იდეა შენი ხდება. შექმნის პროცესში სწორ პასუხზე არანაკლებ მნიშვნელოვანი საკუთარი გზის პოვნაა.',
                        'tag' => 'პატარა ნაბიჯი, დიდი აღმოჩენა',
                        'imageUrl' => '/images/home/archive-creative-hands.webp',
                        'imageAlt' => 'არქივის ფოტო: ბავშვები ფერადი ხელებით შემოქმედებით სივრცეში',
                    ],
                    [
                        'label' => 'საკუთარი ხმის პოვნა',
                        'title' => "შენი აზრი\nჩვენთვის ამბავია.",
                        'body' => 'აზრის თქმა, სხვისი მოსმენა და ერთად პასუხის პოვნა. მეგობრობა იქ იწყება, სადაც ერთმანეთს საკუთარი თავის ყოფნის საშუალებას ვაძლევთ.',
                        'tag' => 'სწავლა ერთმანეთისგან',
                        'imageUrl' => '/images/home/archive-quiz-championship.webp',
                        'imageAlt' => 'სკოლის არქივი: ინტელექტუალური ჩემპიონატის მონაწილეები',
                    ],
                ],
            ],
            [
                'type' => 'programs',
                'eyebrow' => 'შენს ასაკს თავისი აღმოჩენა აქვს',
                'heading' => 'პირველი ნაბიჯიდან —',
                'headingAccent' => 'საკუთარ გზამდე.',
                'aside' => "ვიზრდებით. ვცვლით ინტერესებს.\nსწავლის სურვილი ჩვენთან რჩება.",
                'linkLabel' => 'სწავლის შესახებ',
                'items' => [
                    ['grade' => 'დაწყებითი საფეხური', 'title' => 'პირველი „მე თვითონ!“', 'body' => 'კითხვის სიხარული, პირველი აღმოჩენები და სწავლის სიყვარული. გზა, რომელიც პატარა ნაბიჯით იწყება.', 'note' => 'ცნობისმოყვარეობიდან — თავდაჯერებამდე'],
                    ['grade' => 'საბაზო საფეხური', 'title' => 'ინტერესები საკუთარ გზას პოულობს.', 'body' => 'კითხვებიდან იდეებამდე — მეტი დამოუკიდებლობა, თანამშრომლობა და სივრცე ინტერესების აღმოსაჩენად.', 'note' => 'კითხვებიდან — საკუთარ იდეებამდე'],
                    ['grade' => 'საშუალო საფეხური', 'title' => 'ხვალ უკვე შენი არჩევანია.', 'body' => 'გაცნობიერებული არჩევანი და მომავლისთვის მზადება. საკუთარი გზის დანახვა და შემდეგი ნაბიჯის გამბედაობა.', 'note' => 'შესაძლებლობებიდან — არჩევანამდე'],
                ],
            ],
            [
                'type' => 'portal_promo',
                'eyebrow' => 'სკოლასთან კავშირი, უფრო ახლოს.',
                'heading' => 'მათი დღე.',
                'headingAccent' => 'შენი სიმშვიდე.',
                'body' => 'განრიგი, ნამუშევრები და მნიშვნელოვანი ამბები — ერთ პირად სივრცეში. ნაკლები ძებნა, მეტი დრო ერთმანეთისთვის.',
                'ctaLabel' => 'ჩემი აისი',
                'ctaHref' => '/login',
                // Each one names a live portal module: timetable, portfolio, messages.
                'features' => [
                    ['title' => 'დღის განრიგი', 'body' => 'გეგმა, რომელიც ხელთ გაქვს'],
                    ['title' => 'აღმოჩენების პორტფოლიო', 'body' => 'პატარა ნაბიჯების დიდი ისტორია'],
                    ['title' => 'შეტყობინებები', 'body' => 'მასწავლებელთან ახლოს'],
                ],
                'sticker' => 'კავშირი მნიშვნელოვანია.',
            ],
            [
                'type' => 'life',
                'eyebrow' => 'აისის ამბები',
                'heading' => 'ამბები, რომლებსაც',
                'headingAccent' => 'ერთად ვქმნით.',
                'ctaLabel' => 'ყველა ამბავი',
                'ctaHref' => '/news',
            ],
            [
                'type' => 'contact_cta',
                'eyebrow' => 'შემდეგი ამბავი შეიძლება თქვენი იყოს.',
                'heading' => 'დიდი ხვალ',
                'headingAccent' => 'გაცნობით იწყება.',
                'ctaLabel' => 'მოდი, გაიცანი აისი',
                'sunNote' => "სკოლა. ადამიანები.\nახალი დასაწყისები.",
            ],
        ];
    }
}
