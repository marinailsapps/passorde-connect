<?php

namespace Passorde\Api\Exceptions;

use InvalidArgumentException;

class InvalidPathException extends InvalidArgumentException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct('Chemin Evoliz refusé : '.$path);
    }
}
