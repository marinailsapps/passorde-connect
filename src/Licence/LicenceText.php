<?php

namespace Passorde\Licence;

/**
 * Package strings are French only, whatever the locale of the host application.
 */
final class LicenceText
{
    /**
     * @param  array<string, scalar|null>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $line = trans('passorde::licence.'.$key, $replace, 'fr');

        return is_string($line) ? $line : $key;
    }
}
