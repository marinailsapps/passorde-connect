<?php

namespace Passorde\Reads;

use Generator;
use Passorde\Records\PaytermRecord;

final class ReadPayterms
{
    public function __construct(private readonly Listing $listing) {}

    /**
     * @return Generator<int, PaytermRecord>
     */
    public function all(): Generator
    {
        return $this->listing->records('/payterms', [], PaytermRecord::fromRow(...));
    }
}
