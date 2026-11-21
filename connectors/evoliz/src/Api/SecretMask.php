<?php

namespace Passorde\Api;

/**
 * What the request log must not keep: credentials, tokens, bank details and shareable document links.
 * A null stays null, so that the log still tells "empty" from "hidden".
 */
final class SecretMask
{
    public const MASK = '***';

    private const SENSITIVE_KEY = '/secret|passw|token|api[_-]?key|public_key|authorization|bearer|iban|^bic$|bank_account|bank_identification|webdoc/i';

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function values(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $value !== null && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $values[$key] = self::MASK;
            } elseif (is_array($value)) {
                $values[$key] = self::values($value);
            } elseif (is_string($value)) {
                $values[$key] = self::text($value);
            }
        }

        return $values;
    }

    /**
     * The configured keys, wherever they appear in a text, such as an echoed request.
     */
    public static function text(string $text): string
    {
        foreach (['secret_key', 'public_key'] as $setting) {
            $secret = config('passorde-connect.evoliz.'.$setting);

            if (is_string($secret) && $secret !== '') {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        return $text;
    }
}
