<?php

namespace Passorde\Records;

/**
 * Evoliz stores several labels as HTML fragments. The result is plain text, not safe HTML:
 * an encoded &lt;b&gt; comes out as the characters <b>, so it must be escaped when displayed.
 */
final class Text
{
    public static function clean(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $text = mb_scrub($value, 'UTF-8');
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#isu', ' ', $text);
        // A line break or the end of a block separates words that strip_tags() would otherwise glue together.
        $text = (string) preg_replace('#<br\s*/?>|</(p|div|li|tr|td|h[1-6])\s*>#iu', ' ', $text);
        // Tags go first: once decoded, an encoded "a &lt; b" must not be mistaken for a tag.
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // \p{Z} covers the no-break spaces, including the narrow one used in French typography.
        $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));

        return $text === '' ? null : $text;
    }

    /**
     * The non-empty parts, cleaned and joined.
     *
     * @param  array<int, mixed>  $parts
     */
    public static function join(array $parts, string $separator): ?string
    {
        $cleaned = array_filter(array_map(self::clean(...), $parts), fn (?string $part): bool => $part !== null);

        return $cleaned === [] ? null : implode($separator, $cleaned);
    }

    public static function email(mixed $value): ?string
    {
        $email = self::clean($value);

        if ($email === null) {
            return null;
        }

        $email = mb_strtolower($email, 'UTF-8');

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }
}
