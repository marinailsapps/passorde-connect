<?php

namespace Passorde\Writes\Results;

/**
 * How a write ended. Refusals by a guard are not results: WritesDisabledException, ForbiddenPathException and
 * LicenceException are thrown, before anything leaves for Evoliz.
 */
interface WriteResult
{
    /**
     * Whether Evoliz now holds what was asked for.
     */
    public function succeeded(): bool;
}
