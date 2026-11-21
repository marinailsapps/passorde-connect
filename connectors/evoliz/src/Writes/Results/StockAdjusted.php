<?php

namespace Passorde\Writes\Results;

use Passorde\Records\ArticleRecord;

final readonly class StockAdjusted implements WriteResult
{
    /**
     * @param  string  $before  The stock read just before writing.
     * @param  string  $written  What was written: $before plus the adjustment.
     * @param  string|null  $after  The stock read back; null when that read failed.
     * @param  bool  $confirmed  False when $after is unknown or differs from $written: something else moved the stock, to check in Evoliz.
     * @param  bool  $afterUncertainResponse  True when Evoliz gave no clear answer and reading the stock back showed the change applied,
     *                                        or when a StockExpectation showed an earlier attempt applied and nothing was sent.
     */
    public function __construct(
        public ArticleRecord $record,
        public string $before,
        public string $written,
        public ?string $after,
        public bool $confirmed,
        public bool $afterUncertainResponse = false,
    ) {}

    public function succeeded(): bool
    {
        return true;
    }
}
