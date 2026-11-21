<?php

namespace Passorde\Records;

/**
 * Amounts, rates and quantities as strings with two decimals, so that they compare and hash without float noise.
 */
final class Money
{
    public const ZERO = '0.00';

    public static function orNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (! is_numeric($value)) {
            return null;
        }

        $formatted = number_format((float) $value, 2, '.', '');

        return $formatted === '-0.00' ? self::ZERO : $formatted;
    }

    public static function orZero(mixed $value): string
    {
        return self::orNull($value) ?? self::ZERO;
    }
}
