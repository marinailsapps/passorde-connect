<?php

namespace Passorde\Licence\Exceptions;

use Passorde\Licence\LicenceStatus;
use RuntimeException;

abstract class LicenceException extends RuntimeException
{
    public function __construct(public readonly LicenceStatus $status)
    {
        parent::__construct($status->reason());
    }
}
