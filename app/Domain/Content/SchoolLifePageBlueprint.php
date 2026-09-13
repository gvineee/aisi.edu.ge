<?php

namespace App\Domain\Content;

/**
 * Blocks for the public "school-life" page. Previously just a hero with no
 * real content beneath it (docs/design-parity-checklist.md, 2026-09-13
 * entry flagged it as effectively empty) — now reuses the same real
 * published-posts feed as the homepage's "life" block (see
 * PageController::latestPostsIfNeeded), since the real event/competition
 * posts already published under /news are exactly "school life" content.
 */
class SchoolLifePageBlueprint
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function blocks(): array
    {
        return [
            [
                'type' => 'hero',
                'eyebrow' => 'სასკოლო ცხოვრება',
                'heading' => 'დღეები, რომლებიც გვზრდის.',
                'body' => 'პროექტები, კლუბები და ერთად შექმნილი ამბები.',
            ],
            [
                'type' => 'life',
                'eyebrow' => 'ბოლო ამბები',
                'heading' => 'რას ვაკეთებთ ერთად.',
                'ctaLabel' => 'ყველა ამბავი',
                'ctaHref' => '/news',
            ],
        ];
    }
}
