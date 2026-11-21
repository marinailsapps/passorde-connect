<?php

namespace Passorde\Writes\Support;

/**
 * Whether a record read back already holds what a PATCH asked for.
 */
final class Stored
{
    /**
     * @param  array<array-key, mixed>  $row
     * @param  array<array-key, mixed>  $expected
     */
    public static function holds(array $row, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            $stored = self::storedValue($row, $key);

            if (is_array($value)) {
                if (! is_array($stored) || ! self::holds($stored, $value)) {
                    return false;
                }
            } elseif (self::comparable($stored) !== self::comparable($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evoliz takes address.iso2 and answers address.country.iso2.
     *
     * @param  array<array-key, mixed>  $row
     */
    private static function storedValue(array $row, int|string $key): mixed
    {
        if ($key === 'iso2' && ! isset($row['iso2']) && is_array($row['country'] ?? null)) {
            return $row['country']['iso2'] ?? null;
        }

        return $row[$key] ?? null;
    }

    /**
     * Empty and null are the same to Evoliz; text comes back HTML-encoded or in capitals.
     */
    private static function comparable(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));

        return $text === '' ? null : mb_strtolower($text, 'UTF-8');
    }
}
