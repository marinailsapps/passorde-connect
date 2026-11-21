<?php

namespace Passorde\Reads;

use Generator;
use Passorde\Records\SaleClassificationRecord;

final class ReadSaleClassifications
{
    public function __construct(private readonly Listing $listing) {}

    /**
     * @return Generator<int, SaleClassificationRecord>
     */
    public function all(): Generator
    {
        return $this->listing->records('/sale-classifications', [], SaleClassificationRecord::fromRow(...));
    }
}
