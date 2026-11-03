<?php

namespace App\Domain\Content;

/**
 * Server-side checks on a CMS page's typed blocks, whatever editor produced
 * them (the visual form or the advanced JSON mode). Text is rendered as
 * text by the public page, so the risk is in links: a `javascript:` or
 * `data:` URL in a button would run in a visitor's browser. Only site
 * paths, in-page anchors, http(s), mailto: and tel: are accepted.
 */
class PageBlockRules
{
    /** Keys whose value becomes an href on the public page. */
    private const LINK_KEYS = ['ctaHref', 'href', 'url', 'secondaryHref', 'imageUrl'];

    private const MAX_TEXT = 10000;

    private const MAX_DEPTH = 6;

    /**
     * @param  array<int|string, mixed>  $blocks
     * @return array<string, string> error message by dotted path
     */
    public static function errors(array $blocks): array
    {
        $errors = [];
        self::walk($blocks, 'blocks', 0, $errors);

        return $errors;
    }

    public static function isSafeLink(string $link): bool
    {
        $link = trim($link);

        return preg_match('~^(/(?!/)|#|https?://|mailto:|tel:)~i', $link) === 1
            && preg_match('~[\s<>"\\\\]~', $link) === 0;
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  array<string, string>  $errors
     */
    private static function walk(array $node, string $path, int $depth, array &$errors): void
    {
        if ($depth > self::MAX_DEPTH) {
            $errors[$path] = 'ბლოკების სტრუქტურა ზედმეტად ღრმაა.';

            return;
        }

        foreach ($node as $key => $value) {
            $here = "{$path}.{$key}";

            if (is_array($value)) {
                self::walk($value, $here, $depth + 1, $errors);
            } elseif (is_string($value)) {
                if (mb_strlen($value) > self::MAX_TEXT) {
                    $errors[$here] = 'ტექსტი ზედმეტად გრძელია (მაქს. '.self::MAX_TEXT.' სიმბოლო).';
                } elseif (in_array($key, self::LINK_KEYS, true) && $value !== '' && ! self::isSafeLink($value)) {
                    $errors[$here] = "ბმული „{$value}“ დაუშვებელია. გამოიყენეთ საიტის გვერდი (/about), https:// მისამართი, mailto: ან tel:.";
                }
            } elseif (! is_bool($value) && ! is_int($value) && ! is_float($value) && $value !== null) {
                $errors[$here] = 'ბლოკის მნიშვნელობა არასწორია.';
            }
        }
    }
}
