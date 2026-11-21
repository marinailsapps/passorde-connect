<?php

namespace Passorde\Writes\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Passorde\Api\CacheLocks;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\PaginationLimitException;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Results\WriteResult;

/**
 * Two workers given the same job must not both find nothing and both create: a registered delivery cannot be deleted.
 */
final class WriteLock
{
    /**
     * What the verb reads before writing may fail here: that ends it with nothing written, as a result rather than an
     * exception. Failed, certain, for an API error or a listing too long to read to its end; Refused for a plain 4xx.
     *
     * @template T of WriteResult
     *
     * @param  Closure(): T  $work
     * @return T|Refused|Failed
     */
    public function around(string $key, Closure $work): WriteResult
    {
        $lock = CacheLocks::provider()->lock('passorde:write:'.$key, self::seconds('lock_seconds', 600));

        try {
            $lock->block(self::seconds('lock_wait_seconds', 15));
        } catch (LockTimeoutException) {
            return new Failed(null, 'La même écriture est déjà en cours dans un autre processus ('.$key.').', uncertain: true);
        }

        try {
            return $work();
        } catch (ApiException $exception) {
            return WriteCalls::failureBeforeWriting($exception);
        } catch (PaginationLimitException $exception) {
            return new Failed(null, $exception->getMessage());
        } finally {
            $lock->release();
        }
    }

    private static function seconds(string $key, int $default): int
    {
        $value = config('passorde-connect.writes.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
