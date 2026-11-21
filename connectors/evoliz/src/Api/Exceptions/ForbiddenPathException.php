<?php

namespace Passorde\Api\Exceptions;

use RuntimeException;

class ForbiddenPathException extends RuntimeException
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $pattern,
    ) {
        parent::__construct(sprintf('Chemin Evoliz interdit (%s) : %s %s', $pattern, $method, $path));
    }
}
