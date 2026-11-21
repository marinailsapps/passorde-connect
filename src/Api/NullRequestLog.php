<?php

namespace Passorde\Api;

use Passorde\Api\Contracts\RequestLog;

final class NullRequestLog implements RequestLog
{
    public function start(string $kind, string $method, string $path, array $query = [], ?array $body = null): int
    {
        return 0;
    }

    public function finish(int|string $id, int $attempt, ?int $status, array|string|null $responseBody, ?string $error, RequestOutcome $outcome): void {}
}
