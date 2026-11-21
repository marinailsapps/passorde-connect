<?php

namespace Passorde\Reads;

use Generator;
use Passorde\Records\PaytypeRecord;

final class ReadPaytypes
{
    public function __construct(private readonly Listing $listing) {}

    /**
     * @return Generator<int, PaytypeRecord>
     */
    public function all(): Generator
    {
        return $this->listing->records('/paytypes', [], PaytypeRecord::fromRow(...));
    }
}
