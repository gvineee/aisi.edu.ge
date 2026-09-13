<?php

namespace App\Domain\Content;

/**
 * Blocks for the public "documents" page — real official school documents
 * (financial report, action plans, internal regulations/policies) recovered
 * from the old site's own "დოკუმენტები" menu category and "შიდა
 * რეგულაციები" page (docs/design-parity-checklist.md, 2026-09-13 entry).
 * Every file is the school's real PDF, stored at
 * storage/app/public/tenants/aisi/documents/ under its original filename —
 * titles come from the old site's real post/page titles (or, where the
 * source page's own caption text was corrupted, a plain cleanup of the
 * real filename) — nothing here is invented.
 */
class DocumentsPageBlueprint
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function blocks(): array
    {
        return [
            [
                'type' => 'hero',
                'eyebrow' => 'გამჭვირვალობა',
                'heading' => 'სკოლის ოფიციალური დოკუმენტები.',
                'body' => 'ფინანსური ანგარიშები, სამოქმედო გეგმები და შიდა რეგულაციები — ღიად, ყველასთვის ხელმისაწვდომი.',
            ],
            [
                'type' => 'documents',
                'groups' => [
                    [
                        'heading' => 'ანგარიშები და გეგმები',
                        'items' => [
                            ['title' => 'ფინანსური ანგარიში', 'url' => '/storage/tenants/aisi/documents/ფინანსური-ანგარიში.pdf'],
                            ['title' => 'სკოლის სტრუქტურა', 'url' => '/storage/tenants/aisi/documents/სკოლის-სტრუტქურა-აისი.pdf'],
                            ['title' => '2024-2025 სასწავლო წლების სამოქმედო გეგმის შესრულების ანგარიში', 'url' => '/storage/tenants/aisi/documents/სამოქმედო-გეგმის-შესრულების-ანგარიში-1.pdf'],
                            ['title' => '2025-2026 წლის ერთწლიანი სამოქმედო გეგმა', 'url' => '/storage/tenants/aisi/documents/სამოქმედო-გეგმა-202_-202_-1.pdf'],
                            ['title' => 'მოსწავლეთა ჩარიცხვის, საფეხურის დაძლევის, მობილობის, სტატუსის შეჩერებისა და შეწყვეტის წესი', 'url' => '/storage/tenants/aisi/documents/მოსწავლეთა-ჩარიცხვის-საფეხურის-დაძლევის-მობილობის-სტატუსის-შეჩერებისა-და-შეწყვეტის-წ-1.pdf'],
                            ['title' => 'მოსწავლეთა თვითმართველობის დებულება', 'url' => '/storage/tenants/aisi/documents/მოსწავლეთა-თვითმმართველობა-1.pdf'],
                            ['title' => 'განცხადების/საჩივრის განხილვის პროცედურა', 'url' => '/storage/tenants/aisi/documents/გასაჩივრების-წესი-1.pdf'],
                        ],
                    ],
                    [
                        'heading' => 'შიდა რეგულაციები და პოლიტიკები',
                        'items' => [
                            ['title' => 'სკოლის შინაგანაწესი', 'url' => '/storage/tenants/aisi/documents/შინაგანაწესი-17.09.2021.pdf'],
                            ['title' => 'HR პოლიტიკა', 'url' => '/storage/tenants/aisi/documents/HR-პოლიტიკა.pdf'],
                            ['title' => 'საზოგადოებასთან ურთიერთობისა და კომუნიკაციის წესი', 'url' => '/storage/tenants/aisi/documents/საზოგადოებასთან-ურთიერთობისა-და-კომუნიკაციის-წესი.pdf'],
                            ['title' => 'პერსონალურ მონაცემთა დაცვის მექანიზმი', 'url' => '/storage/tenants/aisi/documents/პერსონალურ-მონაცემთა-დაცვის-მექანიზმი.pdf'],
                            ['title' => 'მშობლებთან ურთიერთობის წესი', 'url' => '/storage/tenants/aisi/documents/მშობლებთან-ურთიერთობის-წესი.pdf'],
                            ['title' => 'კურიკულუმის შემუშავებისა და დანერგვის მეთოდოლოგია', 'url' => '/storage/tenants/aisi/documents/კურიკულუმის-შემუშავებისა-და-დანერგვის-მეთოდოლოგია.pdf'],
                            ['title' => 'კათედრის დებულება', 'url' => '/storage/tenants/aisi/documents/კათედრის-დებულება.pdf'],
                            ['title' => 'ვერიფიკაციის წესი', 'url' => '/storage/tenants/aisi/documents/ვერიფიკაციის-წესი.pdf'],
                            ['title' => 'უსაფრთხოების პოლიტიკის დოკუმენტი', 'url' => '/storage/tenants/aisi/documents/უსაფრთხოების-პოლიტიკის-დოკუმენტი.pdf'],
                            ['title' => 'სტრატეგიული დაგეგმარების მეთოდოლოგია', 'url' => '/storage/tenants/aisi/documents/სტრატეგიული-დაგეგმარების-მეთოდოლოგია.pdf'],
                            ['title' => 'სამედიცინო დახმარების წესი', 'url' => '/storage/tenants/aisi/documents/სამედიცინო-დახმარების-წესი.pdf'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
