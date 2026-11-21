<?php

namespace Passorde\Api\Exceptions;

use Passorde\Api\ApiError;
use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(public readonly ApiError $error)
    {
        parent::__construct($error->message);
    }
}
