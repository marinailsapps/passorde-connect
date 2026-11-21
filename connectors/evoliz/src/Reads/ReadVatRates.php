<?php

namespace Passorde\Reads;

use Generator;
use Passorde\Records\VatRateRecord;

final class ReadVatRates
{
    public function __construct(private readonly Listing $listing) {}

    /**
     * @return Generator<int, VatRateRecord>
     */
    public function all(): Generator
    {
        return $this->listing->records('/vat-rates', [], VatRateRecord::fromRow(...));
    }
}
