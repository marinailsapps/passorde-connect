<?php

namespace Passorde\Sinks\Contracts;

use Carbon\CarbonImmutable;
use Passorde\Records\SyncedRecord;

/**
 * Receives what a full listing returned. Passorde binds its own implementations, which write Evoliz data
 * into its own tables instead of the passorde_ mirror.
 *
 * @template T of SyncedRecord
 */
interface ListingSink
{
    /**
     * Called with up to 100 records at a time. Must leave an unchanged record's modification date alone.
     *
     * @param  list<T>  $records
     * @return int How many stored rows really changed.
     */
    public function upsert(array $records, CarbonImmutable $syncedAt): int;

    /**
     * Called once, after a complete and non-empty listing: anything not in $seenIds is gone from Evoliz.
     * Must not delete it.
     *
     * @param  list<int>  $seenIds
     * @return int How many stored rows were flagged.
     */
    public function deactivateMissing(array $seenIds, CarbonImmutable $syncedAt): int;
}
