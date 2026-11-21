<?php

namespace Passorde\Api;

use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\ForbiddenPathException;
use Passorde\Api\Exceptions\PaginationLimitException;
use Passorde\Api\Exceptions\WritesDisabledException;
use Passorde\Evoliz\EvolizConnector;
use Passorde\Licence\LicenceGuard;
use SensitiveParameter;

final class EvolizClient implements EvolizApi
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly EvolizToken $token,
        private readonly RateLimitMonitor $rateLimit,
        private readonly LicenceGuard $licence,
    ) {}

    public function get(string $path, array $query = []): array
    {
        $response = $this->send('GET', $path, ['query' => $query]);

        if (! $response->successful()) {
            throw new ApiException(ApiError::fromResponse($response));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ApiException(new ApiError($response->status(), 'HTTP '.$response->status().' — réponse sans JSON exploitable'));
        }

        return $body;
    }

    public function paginate(string $path, array $query = [], int $perPage = 100): Generator
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $firstPage = is_numeric($query['page'] ?? null) ? max(1, (int) $query['page']) : 1;
        $maxPages = self::setting('max_pages', 1000);

        for ($page = $firstPage, $fetched = 0; ; $page++, $fetched++) {
            if ($fetched >= $maxPages) {
                // Stopping quietly would pass a partial list off as the complete one.
                throw new PaginationLimitException($path, $maxPages);
            }

            $body = $this->get($path, array_merge($query, ['page' => $page, 'per_page' => $perPage]));
            $rows = is_array($body['data'] ?? null) ? array_values($body['data']) : [];
            $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
            $lastPage = is_numeric($meta['last_page'] ?? null) ? (int) $meta['last_page'] : $page;

            yield $rows;

            if ($page >= $lastPage || $rows === []) {
                return;
            }
        }
    }

    public function send(string $method, string $path, array $options = []): Response
    {
        $method = strtoupper($method);
        $target = ApiPath::parse($path);

        $this->guard($method, $path, $target);

        $url = Credentials::fromConfig()->baseUrl.$target->scopedTo(Credentials::fromConfig()->companyId);
        $options['query'] = array_merge($target->query, $options['query'] ?? []);
        $retriedUnauthorized = false;
        $retriedRateLimit = false;

        while (true) {
            $response = $this->dispatch($method, $url, $options, $this->token->get());
            $this->rateLimit->observe($response);

            if ($response->status() === 401) {
                $this->token->forget();

                if (! $retriedUnauthorized) {
                    $retriedUnauthorized = true;

                    continue;
                }
            }

            if ($response->status() === 429 && ! $retriedRateLimit) {
                $retriedRateLimit = true;
                $this->rateLimit->waitAfter429($response);

                continue;
            }

            return $response;
        }
    }

    private function guard(string $method, string $path, ApiPath $target): void
    {
        if ($method !== 'GET' && ! config('passorde-connect.writes_enabled')) {
            throw new WritesDisabledException($method, $path);
        }

        $pattern = ForbiddenPaths::fromConfig()->matchCall($method, $target);

        if ($pattern !== null) {
            throw new ForbiddenPathException($method, $path, $pattern);
        }

        $this->licence->check(EvolizConnector::ID);
    }

    /**
     * @param  array{query?: array<array-key, mixed>, json?: array<array-key, mixed>, timeout?: int}  $options
     */
    private function dispatch(string $method, string $url, array $options, #[SensitiveParameter] string $token): Response
    {
        $sent = array_intersect_key($options, array_flip(['query', 'json']));

        // Redirects are refused: the token stays on the configured host, and a redirect cannot reach a forbidden path.
        return Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->timeout($options['timeout'] ?? self::setting('timeout', 30))
            ->connectTimeout(self::setting('connect_timeout', 10))
            ->withoutRedirecting()
            ->send($method, $url, $sent);
    }

    private static function setting(string $key, int $default): int
    {
        $value = config('passorde-connect.evoliz.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
