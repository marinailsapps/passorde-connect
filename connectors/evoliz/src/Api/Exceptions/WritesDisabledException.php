<?php

namespace Passorde\Api\Exceptions;

use RuntimeException;

class WritesDisabledException extends RuntimeException
{
    public function __construct(public readonly string $method, public readonly string $path)
    {
        parent::__construct(sprintf('Écritures vers Evoliz désactivées : %s %s', $method, $path));
    }
}
