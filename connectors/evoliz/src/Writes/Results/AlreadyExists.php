<?php

namespace Passorde\Writes\Results;

use Passorde\Records\SyncedRecord;

/**
 * @template T of SyncedRecord
 */
final readonly class AlreadyExists implements WriteResult
{
    /**
     * @param  T  $record
     * @param  bool  $afterUncertainResponse  True when this very call sent the create, got no clear answer and then found the record: most likely its own.
     */
    public function __construct(public SyncedRecord $record, public bool $afterUncertainResponse = false) {}

    public function succeeded(): bool
    {
        return true;
    }
}
