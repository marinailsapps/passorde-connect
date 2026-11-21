<?php

namespace Passorde\Drafts;

/**
 * What an earlier AdjustStock call read and tried to write, as its Failed result reported them. Given back to
 * AdjustStock::run() on the retry, it lets the adjustment recognise itself instead of counting twice.
 */
final readonly class StockExpectation
{
    /**
     * @param  string  $before  The stock read before that attempt.
     * @param  string  $written  The stock that attempt tried to write.
     */
    public function __construct(public string $before, public string $written) {}
}
