<?php

namespace Passorde\Api\Contracts;

use Passorde\Api\RequestOutcome;

interface RequestLog
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<array-key, mixed>|null  $body
     * @return int|string The id to give to finish().
     */
    public function start(string $kind, string $method, string $path, array $query = [], ?array $body = null): int|string;

    /**
     * May be called once per attempt: the outcome stays Running while another attempt follows.
     *
     * @param  array<array-key, mixed>|string|null  $responseBody
     */
    public function finish(
        int|string $id,
        int $attempt,
        ?int $status,
        array|string|null $responseBody,
        ?string $error,
        RequestOutcome $outcome,
    ): void;
}
