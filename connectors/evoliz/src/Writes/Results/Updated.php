<?php

namespace Passorde\Writes\Results;

use Passorde\Records\SyncedRecord;

/**
 * @template T of SyncedRecord
 */
final readonly class Updated implements WriteResult
{
    /**
     * @param  T  $record
     * @param  bool  $afterUncertainResponse  True when Evoliz gave no clear answer and reading the record back showed the change applied.
     */
    public function __construct(public SyncedRecord $record, public bool $afterUncertainResponse = false) {}

    public function succeeded(): bool
    {
        return true;
    }
}
