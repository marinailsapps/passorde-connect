<?php

namespace Passorde\Sync;

use Passorde\Reads\ReadClients;
use Passorde\Records\ClientRecord;
use Passorde\Sinks\Contracts\ClientSink;
use Passorde\Sinks\Contracts\ListingSink;

/**
 * @extends ListingSync<ClientRecord>
 */
final class SyncClients extends ListingSync
{
    public function __construct(RunGuard $runs, private readonly ReadClients $reader, private readonly ClientSink $target)
    {
        parent::__construct($runs);
    }

    protected function kind(): SyncKind
    {
        return SyncKind::Clients;
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
