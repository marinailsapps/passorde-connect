<?php

namespace Passorde\Writes\Support;

use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;

/**
 * What became of one write request.
 */
final readonly class Sent
{
    /**
     * @param  array<array-key, mixed>  $row  What Evoliz answered, or what the lookup found.
     * @param  bool  $recovered  True when the row comes from the lookup rather than from the answer.
     */
    public function __construct(
        public array $row = [],
        public bool $recovered = false,
        public Refused|Failed|null $problem = null,
    ) {}
}
