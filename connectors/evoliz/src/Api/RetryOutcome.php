<?php

namespace Passorde\Api;

use Illuminate\Http\Client\Response;

final readonly class RetryOutcome
{
    /**
     * @param  Response|null  $response  The successful response; null when the lookup found the resource.
     * @param  mixed  $found  What the lookup returned.
     * @param  int  $attempts  How many times the call was made.
     */
    public function __construct(
        public ?Response $response,
        public mixed $found,
        public int $attempts,
    ) {}

    public function foundByLookup(): bool
    {
        return $this->response === null;
    }
}
