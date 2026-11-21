<?php

namespace Passorde\Licence;

use JsonException;
use stdClass;

/**
 * The licence key: one line of text that carries the whole signed licence, its refresh token included.
 *
 *     pck1.<base64url, no padding, of the UTF-8 JSON object of the licence with its signature>
 *
 * The issuer writes the JSON in canonical form with the signature among the fields (see CanonicalJson::encodeWhole());
 * a reader accepts any JSON object, since only the signature over the canonical bytes counts. Whitespace anywhere in
 * the key is ignored, so that a key wrapped by a mail client still installs. Spec: docs/licence-key.md.
 */
final class LicenceKey
{
    public const PREFIX = 'pck1.';

    /** Far above any real key (about 700 characters); anything longer is refused before decoding. */
    public const MAX_LENGTH = 16384;

    /**
     * The key for a signed licence, given as its JSON object.
     *
     * @throws JsonException
     */
    public static function encode(stdClass $licence): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode(CanonicalJson::encodeWhole($licence)), '+/', '-_'), '=');
    }

    /**
     * The key for a signed licence given as JSON text: what a test helper or a converter needs.
     *
     * @throws JsonException
     */
    public static function fromJson(string $json): string
    {
        $licence = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        if (! $licence instanceof stdClass) {
            throw new JsonException('A licence is a JSON object.');
        }

        return self::encode($licence);
    }

    /**
     * The key as it is stored: every whitespace character removed.
     */
    public static function normalize(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }

    /**
     * The licence fields that the key carries, signature included and not yet verified, or why it is not a key.
     */
    public static function decode(string $text): stdClass|LicenceReason
    {
        $key = self::normalize($text);

        if (str_starts_with($key, '{')) {
            return LicenceReason::LegacyFormat;
        }
        if ($key === '' || strlen($key) > self::MAX_LENGTH) {
            return LicenceReason::MalformedKey;
        }
        if (preg_match('/^pck(\d+)\./i', $key, $version) === 1 && $version[1] !== '1') {
            return LicenceReason::UnsupportedKeyVersion;
        }
        if (strncasecmp($key, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return LicenceReason::MalformedKey;
        }

        $body = substr($key, strlen(self::PREFIX));

        if (preg_match('/^[A-Za-z0-9_-]+\z/', $body) !== 1 || strlen($body) % 4 === 1) {
            return LicenceReason::MalformedKey;
        }

        $json = base64_decode(strtr($body, '-_', '+/'), true);

        if ($json === false) {
            return LicenceReason::MalformedKey;
        }

        try {
            $fields = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return LicenceReason::MalformedKey;
        }

        return $fields instanceof stdClass ? $fields : LicenceReason::MalformedKey;
    }
}
