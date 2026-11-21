<?php

namespace Passorde\Writes\Results;

/**
 * The call did not come to an end: Evoliz or the network failed, or the credentials were refused.
 */
final readonly class Failed implements WriteResult
{
    /**
     * @param  int|null  $status  Null when no response came back.
     * @param  bool  $uncertain  True when the write may have gone through all the same.
     * @param  string|null  $before  AdjustStock only: the stock read before writing, null when nothing was written.
     * @param  string|null  $written  AdjustStock only: the stock it tried to write. With $before, the StockExpectation to give back on the retry.
     */
    public function __construct(
        public ?int $status,
        public string $message,
        public bool $uncertain = false,
        public ?string $before = null,
        public ?string $written = null,
    ) {}

    public function succeeded(): bool
    {
        return false;
    }
}
