<?php

namespace Passorde\Licence;

use stdClass;

/**
 * The exact bytes covered by a licence signature.
 *
 * Input must be decoded with objects, not associative arrays: an array cannot tell {} from [],
 * and re-encoding one as the other would change the signed bytes.
 */
final class CanonicalJson
{
    /**
     * JSON_UNESCAPED_LINE_TERMINATORS is needed as well: without it PHP escapes U+2028 and U+2029
     * even under JSON_UNESCAPED_UNICODE, whereas JavaScript's JSON.stringify writes them raw.
     */
    private const FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_THROW_ON_ERROR;

    /**
     * Encodes every field of the licence except the signature.
     */
    public static function encode(stdClass $licence): string
    {
        $fields = clone $licence;
        unset($fields->signature);

        return json_encode(self::sorted($fields), self::FLAGS);
    }

    /**
     * Encodes every field, the signature included: the JSON that a licence key carries.
     */
    public static function encodeWhole(stdClass $licence): string
    {
        return json_encode(self::sorted($licence), self::FLAGS);
    }

    private static function sorted(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::sorted(...), $value);
        }

        if (! $value instanceof stdClass) {
            return $value;
        }

        $fields = get_object_vars($value);

        // Comparing UTF-8 bytes gives the same order as comparing code points.
        ksort($fields, SORT_STRING);

        $sorted = new stdClass;
        foreach ($fields as $key => $field) {
            $sorted->{(string) $key} = self::sorted($field);
        }

        return $sorted;
    }
}
