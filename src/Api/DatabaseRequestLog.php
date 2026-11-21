<?php

namespace Passorde\Api;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Passorde\Api\Contracts\RequestLog;

/**
 * Rows of the passorde_requests table. The token and the keys never reach it: only business calls are logged.
 * The subject columns come from RequestContext::subject().
 */
final class DatabaseRequestLog implements RequestLog
{
    private const TABLE = 'passorde_requests';

    private const MAX_RESPONSE_BYTES = 65535;

    public function start(string $kind, string $method, string $path, array $query = [], ?array $body = null): int
    {
        $now = CarbonImmutable::now();
        $subject = RequestContext::current();

        return DB::table(self::TABLE)->insertGetId([
            'kind' => $kind,
            'method' => $method,
            'path' => $this->withQuery($path, $query),
            'payload' => $body === null ? null : $this->encode($body),
            'status' => RequestOutcome::Running->value,
            'attempts' => 0,
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => $subject['id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function finish(int|string $id, int $attempt, ?int $status, array|string|null $responseBody, ?string $error, RequestOutcome $outcome): void
    {
        DB::table(self::TABLE)->where('id', $id)->update([
            'attempts' => $attempt,
            'http_status' => $status,
            'response' => $this->encodeResponse($responseBody),
            'status' => $outcome->value,
            'last_error' => $error,
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function withQuery(string $path, array $query): string
    {
        return $query === [] ? $path : $path.(str_contains($path, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * @param  array<array-key, mixed>|string|null  $body
     */
    private function encodeResponse(array|string|null $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $encoded = is_array($body) ? $this->encode($body) : $this->encode(['raw' => $body]);

        if (strlen($encoded) <= self::MAX_RESPONSE_BYTES) {
            return $encoded;
        }

        return $this->encode(['raw' => Str::limit($encoded, self::MAX_RESPONSE_BYTES - 1024, '…'), 'truncated' => true]);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
