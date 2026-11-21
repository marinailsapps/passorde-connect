<?php

namespace Passorde\Licence\Contracts;

use DateTimeImmutable;
use Passorde\Licence\Exceptions\LicenceStoreException;

interface LicenceStore
{
    /**
     * The raw current licence, or null when none is installed.
     */
    public function current(): ?string;

    /**
     * The raw licence that the current one replaced, or null.
     */
    public function previous(): ?string;

    /**
     * Makes this the current licence and keeps the one it replaces as the previous one.
     *
     * @throws LicenceStoreException
     */
    public function replace(string $raw): void;

    public function lastRefreshAt(): ?DateTimeImmutable;

    /**
     * @throws LicenceStoreException
     */
    public function markRefreshed(DateTimeImmutable $at): void;
}
