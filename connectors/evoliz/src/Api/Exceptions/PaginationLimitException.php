<?php

namespace Passorde\Api\Exceptions;

use RuntimeException;

class PaginationLimitException extends RuntimeException
{
    public function __construct(public readonly string $path, public readonly int $maxPages)
    {
        parent::__construct(sprintf('Pagination Evoliz interrompue après %d pages : %s', $maxPages, $path));
    }
}
