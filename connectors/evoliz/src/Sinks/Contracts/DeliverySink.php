<?php

namespace Passorde\Sinks\Contracts;

use Carbon\CarbonImmutable;
use Passorde\Records\DeliveryRecord;

/**
 * Receives the deliveries of a date window. Passorde binds its own implementation in place of the passorde_ mirror.
 */
interface DeliverySink
{
    /**
     * Called with up to 100 records at a time. A record that had been removed comes back.
     *
     * @param  list<DeliveryRecord>  $records
     * @return int How many stored deliveries really changed, their lines included.
     */
    public function upsert(array $records, CarbonImmutable $syncedAt): int;

    /**
     * Called once, after a complete and non-empty listing of the window (both dates included):
     * a delivery dated in the window and absent from $seenIds is gone from Evoliz.
     *
     * @param  list<int>  $seenIds
     * @return int How many stored deliveries were removed.
     */
    public function removeMissing(CarbonImmutable $from, CarbonImmutable $to, array $seenIds, CarbonImmutable $syncedAt): int;
}
