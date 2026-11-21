<?php

namespace Passorde\Records;

/**
 * Typed access to a decoded Evoliz row, which may lack any field.
 */
final class Row
{
    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function id(array $row, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function bool(array $row, string $key, bool $default): bool
    {
        return filter_var($row[$key] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<array-key, mixed>
     */
    public static function nested(array $row, string $key): array
    {
        return is_array($row[$key] ?? null) ? $row[$key] : [];
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function int(array $row, string $key): ?int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;
    }
}
