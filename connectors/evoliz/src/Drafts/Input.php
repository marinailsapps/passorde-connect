<?php

namespace Passorde\Drafts;

/**
 * Checks shared by the drafts.
 */
final class Input
{
    public static function required(string $field, string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw InvalidDraftException::field($field, 'obligatoire');
        }

        return $value;
    }

    public static function id(string $field, int $value): int
    {
        if ($value <= 0) {
            throw InvalidDraftException::field($field, 'identifiant Evoliz attendu');
        }

        return $value;
    }

    /**
     * A number as JSON should carry it: whole values as integers, as Evoliz returns them.
     */
    public static function number(string $field, int|float|string $value): int|float
    {
        $value = is_string($value) ? trim($value) : $value;

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw InvalidDraftException::field($field, 'nombre attendu');
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }

    /**
     * The value for a field of a partial update: null leaves it alone, an empty text clears it.
     */
    public static function patchValue(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
