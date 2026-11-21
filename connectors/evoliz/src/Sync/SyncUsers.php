<?php

namespace Passorde\Sync;

use Passorde\Reads\ReadUsers;
use Passorde\Records\UserRecord;
use Passorde\Sinks\Contracts\ListingSink;
use Passorde\Sinks\Contracts\UserSink;

/**
 * @extends ListingSync<UserRecord>
 */
final class SyncUsers extends ListingSync
{
    public function __construct(RunGuard $runs, private readonly ReadUsers $reader, private readonly UserSink $target)
    {
        parent::__construct($runs);
    }

    protected function kind(): SyncKind
    {
        return SyncKind::Users;
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
