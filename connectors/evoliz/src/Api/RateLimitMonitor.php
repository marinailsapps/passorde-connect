<?php

namespace Passorde\Api;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Keeps the calls under the Evoliz limit of 100 requests per minute, per IP.
 */
final class RateLimitMonitor
{
    private ?int $remaining = null;

    /**
     * Reads the limit headers, and pauses when few calls remain. A 429 only records the state:
     * its own Retry-After wait covers the pause.
     */
    public function observe(Response $response): void
    {
        $remaining = $this->headerValue($response, 'X-RateLimit-Remaining');

        if ($remaining === null) {
            return;
        }

        $this->remaining = $remaining;
        $limit = $this->headerValue($response, 'X-RateLimit-Limit');

        Log::debug('Passorde: Evoliz rate limit.', ['limit' => $limit, 'remaining' => $remaining]);

        if ($remaining >= self::configured('low_remaining', 10) || $response->status() === 429) {
            return;
        }

        $pause = self::configured('pause_seconds', 60);

        Log::warning('Passorde: Evoliz rate limit nearly exhausted, pausing.', [
            'limit' => $limit,
            'remaining' => $remaining,
            'pause_seconds' => $pause,
        ]);

        Sleep::for($pause)->seconds();
    }

    /**
     * Waits as long as a 429 asks, within the configured bound.
     */
    public function waitAfter429(Response $response): void
    {
        $max = self::configured('max_wait_seconds', 60);

        Sleep::for(max(1, min(RetryAfter::seconds($response) ?? $max, $max)))->seconds();
    }

    /**
     * What the last response said, or null before any response carried the header.
     */
    public function remaining(): ?int
    {
        return $this->remaining;
    }

    private function headerValue(Response $response, string $name): ?int
    {
        $value = trim($response->header($name));

        return ctype_digit($value) ? (int) $value : null;
    }

    private static function configured(string $key, int $default): int
    {
        $value = config('passorde-connect.evoliz.rate_limit.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
