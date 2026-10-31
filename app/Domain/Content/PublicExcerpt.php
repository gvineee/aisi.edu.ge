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
        $text = trim((string) preg_replace('~\s+~u', ' ', $text), " \t\n\r\0\x0B-–—:|·");

        return $text === '' ? null : $text;
    }
}
