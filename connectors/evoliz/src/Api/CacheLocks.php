<?php

namespace Passorde\Api;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class CacheLocks
{
    public static function provider(): LockProvider
    {
        $store = config('passorde-connect.cache_store');
        $locks = Cache::store(is_string($store) && $store !== '' ? $store : null)->getStore();

        if (! $locks instanceof LockProvider) {
            throw new RuntimeException('Le cache configuré pour Passorde ne fournit pas de verrous.');
        }

        return $locks;
    }
}
