<?php

namespace Passorde\Sync;

use Passorde\Reads\ReadPaytypes;
use Passorde\Records\PaytypeRecord;
use Passorde\Sinks\Contracts\ListingSink;
use Passorde\Sinks\Contracts\PaytypeSink;

/**
 * @extends ListingSync<PaytypeRecord>
 */
final class SyncPaytypes extends ListingSync
{
    public function __construct(RunGuard $runs, private readonly ReadPaytypes $reader, private readonly PaytypeSink $target)
    {
        parent::__construct($runs);
    }

    protected function kind(): SyncKind
    {
        return SyncKind::Paytypes;
    }

    protected function records(): iterable
    {
        return $this->reader->all();
    }

    protected function sink(): ListingSink
    {
        return $this->target;
    }
}
