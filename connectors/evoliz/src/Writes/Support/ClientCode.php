<?php

namespace Passorde\Writes\Support;

use Illuminate\Support\Str;
use Passorde\Drafts\ClientDraft;

/**
 * The Evoliz-side code of a client: capital letters of its name, ten at most, then a number when another client has it.
 */
final class ClientCode
{
    private const LENGTH = 10;

    private const LAST_SUFFIX = 99;

    /**
     * @return list<string> The codes to try, in order.
     */
    public static function candidates(ClientDraft $draft): array
    {
        $base = self::base($draft->name);
        $preferred = $draft->code === null ? '' : trim($draft->code);
        $codes = $preferred === '' ? [$base] : [$preferred, $base];

        for ($suffix = 2; $suffix <= self::LAST_SUFFIX; $suffix++) {
            $codes[] = substr($base, 0, self::LENGTH - strlen((string) $suffix)).$suffix;
        }

        return array_values(array_unique($codes));
    }

    /**
     * Two clients can share a name, and one code cannot serve both: name and postcode must both match.
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function isSameClient(array $row, ClientDraft $draft): bool
    {
        $address = is_array($row['address'] ?? null) ? $row['address'] : [];

        return self::comparable($row['name'] ?? null) === self::comparable($draft->name)
            && self::comparable($address['postcode'] ?? null) === self::comparable($draft->postcode);
    }

    private static function base(string $name): string
    {
        $letters = (string) preg_replace('/[^A-Z]/', '', strtoupper(Str::ascii($name)));

        return substr($letters === '' ? 'CLIENT' : $letters, 0, self::LENGTH);
    }

    private static function comparable(mixed $value): string
    {
        $text = is_scalar($value) ? html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';

        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper(Str::ascii($text)));
    }
}
