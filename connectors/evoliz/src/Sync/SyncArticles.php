<?php

namespace Passorde\Sync;

use Passorde\Reads\ReadArticles;
use Passorde\Records\ArticleRecord;
use Passorde\Sinks\Contracts\ArticleSink;
use Passorde\Sinks\Contracts\ListingSink;

/**
 * @extends ListingSync<ArticleRecord>
 */
final class SyncArticles extends ListingSync
{
    public function __construct(RunGuard $runs, private readonly ReadArticles $reader, private readonly ArticleSink $target)
    {
        parent::__construct($runs);
    }

    protected function kind(): SyncKind
    {
        return SyncKind::Articles;
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
