<?php

namespace Passorde\Licence;

use Illuminate\Contracts\Container\Container;
use Passorde\Licence\Exceptions\LicenceExpiredException;
use Passorde\Licence\Exceptions\LicenceInvalidException;

/**
 * To be called by a connector before any call to the service it connects to, with its own id.
 *
 * The manager is looked up at each check rather than kept, so that a connector client resolved once for the life of
 * a worker follows a licence store or manager that the host binds afterwards.
 */
final class LicenceGuard
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  string|null  $connector  The connector about to call out; null means the default connector.
     *
     * @throws LicenceExpiredException
     * @throws LicenceInvalidException
     */
    public function check(?string $connector = null): LicenceStatus
    {
        $status = $this->container->make(LicenceManager::class)->status($connector);

        return match ($status->state) {
            LicenceState::Valid, LicenceState::Grace => $status,
            LicenceState::Expired => throw new LicenceExpiredException($status),
            LicenceState::Invalid => throw new LicenceInvalidException($status),
        };
    }
}
