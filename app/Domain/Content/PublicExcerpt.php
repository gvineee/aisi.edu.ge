<?php

namespace App\Domain\Content;

/**
 * A teaser as a person reads it. Imported posts often carry the old site's
 * attachment widget in their excerpt ("Download", "report_2023.pdf"); that
 * noise is dropped for display only — the stored excerpt is untouched.
 */
class PublicExcerpt
{
    public static function clean(?string $excerpt): ?string
    {
        if ($excerpt === null) {
            return null;
        }

        $text = preg_replace([
            '~\S+\.(pdf|docx?|xlsx?|pptx?|odt|zip|rar|jpe?g|png)\b~iu',
            '~\b(download|ჩამოტვირთვა)\b~iu',
            '~\(\s*\d+(?:[.,]\d+)?\s*(kb|mb|კბ|მბ)\s*\)~iu',
        ], ' ', $excerpt) ?? $excerpt;
        // Multibyte-safe: trim()'s byte list would cut the last byte off a
        // Georgian letter (e.g. "ე" ends in the same byte as "—").
        $text = preg_replace(['~\s+~u', '~^[\s\-–—:|·]+|[\s\-–—:|·]+$~u'], [' ', ''], $text);

        // A broken string must never reach the page (json_encode would fail).
        if ($text === null || ! mb_check_encoding($text, 'UTF-8')) {
            return $excerpt;
        }

        return $text === '' ? null : $text;
    }
}
