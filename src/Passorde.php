<?php

namespace Passorde;

final class Passorde
{
    public const VERSION = '1.0.0-beta.5';

    /**
     * The day this version is released, YYYY-MM-DD in Europe/Paris: set at each release, with the CHANGELOG heading.
     * A licence with updates_until covers a version only if it was released on or before that day.
     */
    public const RELEASED_AT = '2026-11-21';

    /**
     * Whether the host has the component switched on (passorde-connect.enabled). Switched off, no provider registers
     * a command, a route or a scheduled task: nothing about a connector or a licence is listed or shown.
     */
    public static function enabled(): bool
    {
        return config('passorde-connect.enabled') !== false;
    }
}
