<?php

namespace Passorde\Writes\Results;

use Passorde\Records\SyncedRecord;

/**
 * @template T of SyncedRecord
 */
final readonly class Created implements WriteResult
{
    /**
     * @param  T  $record
     */
    public function __construct(public SyncedRecord $record) {}

    public function succeeded(): bool
    {
        return true;
    }
}
