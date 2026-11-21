<?php

namespace Passorde\Writes\Support;

use Closure;
use Passorde\Api\ApiError;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\Contracts\RequestLog;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\LoginFailedException;
use Passorde\Api\Exceptions\PaginationLimitException;
use Passorde\Api\RequestRunner;
use Passorde\Api\RetryPolicy;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;

/**
 * The calls a verb makes, each one retried and logged. The guards throw from here, untouched.
 */
final class WriteCalls
{
    private const PER_PAGE = 100;

    private RequestRunner $runner;

    /** Inside a retry the outer policy counts the attempts: one call per lookup, or 5 × 5. */
    private RequestRunner $singleAttempt;

    public function __construct(EvolizApi $api, RetryPolicy $policy, RequestLog $log)
    {
        $this->runner = new RequestRunner($api, $policy, $log);
        $this->singleAttempt = new RequestRunner($api, new RetryPolicy(maxAttempts: 1), $log);
    }

    /**
     * A write. After a 5xx or a network failure the $lookup runs before the request goes again, and once more when
     * the attempts run out: a row from it means the write went through.
     *
     * @param  array<array-key, mixed>  $body
     * @param  (Closure(): (array<array-key, mixed>|null))|null  $lookup
     */
    public function send(string $kind, string $method, string $path, array $body, ?Closure $lookup = null): Sent
    {
        try {
            $outcome = $this->runner->run($kind, $method, $path, ['json' => $body], $lookup === null ? null : fn (): ?array => $this->lookupDuringRetry($lookup));
        } catch (ApiException $exception) {
            return new Sent(problem: self::failure($exception));
        } catch (UncertainWrite $exception) {
            return new Sent(problem: new Failed(null, $exception->getMessage(), uncertain: true));
        }

        if ($outcome->foundByLookup()) {
            return new Sent(is_array($outcome->found) ? $outcome->found : [], recovered: true);
        }

        $answer = $outcome->response?->json();

        return new Sent(is_array($answer) ? self::unwrapped($answer) : []);
    }

    /**
     * One row. The policy turns a network failure into an ApiException too.
     *
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     *
     * @throws ApiException
     */
    public function read(string $kind, string $path, array $query = []): array
    {
        return self::unwrapped($this->json($this->runner, $kind, $path, $query));
    }

    /**
     * The same with a single attempt, for inside a retry.
     *
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     *
     * @throws ApiException
     */
    public function readOnce(string $kind, string $path, array $query = []): array
    {
        return self::unwrapped($this->json($this->singleAttempt, $kind, $path, $query));
    }

    /**
     * The first row of a listing that $matches accepts, reading every page until then.
     *
     * @param  array<string, mixed>  $query
     * @param  Closure(array<array-key, mixed>): bool  $matches
     * @return array<array-key, mixed>|null
     *
     * @throws ApiException
     * @throws PaginationLimitException Rather than conclude that a row is absent from a list not read to its end.
     */
    public function first(string $kind, string $path, array $query, Closure $matches, bool $singleAttempt = false): ?array
    {
        foreach ($this->pages($kind, $path, $query, $singleAttempt) as $rows) {
            foreach ($rows as $row) {
                if (is_array($row) && $matches($row)) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * Every row of a listing.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<array-key, mixed>>
     *
     * @throws ApiException
     * @throws PaginationLimitException
     */
    public function all(string $kind, string $path, array $query, bool $singleAttempt = false): array
    {
        $all = [];

        foreach ($this->pages($kind, $path, $query, $singleAttempt) as $rows) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }
        }

        return $all;
    }

    /**
     * A write that came to no clear end.
     */
    public static function failure(ApiException $exception): Refused|Failed
    {
        $error = $exception->error;

        return self::isRefusal($exception)
            ? Refused::fromError($error)
            : new Failed($error->status, $error->message, self::mayHaveGoneThrough($error));
    }

    /**
     * A read that failed before anything was written.
     */
    public static function failureBeforeWriting(ApiException $exception): Refused|Failed
    {
        $error = $exception->error;

        return self::isRefusal($exception) ? Refused::fromError($error) : new Failed($error->status, $error->message);
    }

    /**
     * The policy keeps trying through a lookup that fails for a passing reason. Any other failure ends the write
     * in doubt, whereas to the policy, and to the caller, it would read as a refusal of the write itself.
     *
     * @param  Closure(): (array<array-key, mixed>|null)  $lookup
     * @return array<array-key, mixed>|null
     */
    private function lookupDuringRetry(Closure $lookup): ?array
    {
        try {
            return $lookup();
        } catch (ApiException $exception) {
            if ($exception->error->isRetryable()) {
                throw $exception;
            }

            throw new UncertainWrite($exception->getMessage(), previous: $exception);
        } catch (PaginationLimitException $exception) {
            throw new UncertainWrite($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * The API said no to this request: a 4xx, other than the credentials it could not take and the rate limit.
     */
    private static function isRefusal(ApiException $exception): bool
    {
        $status = $exception->error->status;

        return ! $exception instanceof LoginFailedException
            && $status !== null && $status >= 400 && $status < 500 && $status !== 401 && $status !== 429;
    }

    private static function mayHaveGoneThrough(ApiError $error): bool
    {
        return $error->status === null || $error->status >= 500;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return iterable<int, array<array-key, mixed>>
     */
    private function pages(string $kind, string $path, array $query, bool $singleAttempt): iterable
    {
        $maxPages = config('passorde-connect.evoliz.max_pages');
        $maxPages = is_numeric($maxPages) ? (int) $maxPages : 1000;
        $runner = $singleAttempt ? $this->singleAttempt : $this->runner;

        for ($page = 1; ; $page++) {
            if ($page > $maxPages) {
                throw new PaginationLimitException($path, $maxPages);
            }

            $body = $this->json($runner, $kind, $path, array_merge($query, ['per_page' => self::PER_PAGE, 'page' => $page]));
            $rows = is_array($body['data'] ?? null) ? array_values($body['data']) : [];
            $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
            $lastPage = is_numeric($meta['last_page'] ?? null) ? (int) $meta['last_page'] : $page;

            yield $rows;

            if ($page >= $lastPage || $rows === []) {
                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     */
    private function json(RequestRunner $runner, string $kind, string $path, array $query): array
    {
        $response = $runner->run($kind, 'GET', $path, $query === [] ? [] : ['query' => $query])->response;
        $body = $response?->json();

        if (! is_array($body)) {
            $status = $response?->status();

            throw new ApiException(new ApiError($status, 'HTTP '.$status.' — réponse sans JSON exploitable'));
        }

        return $body;
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    private static function unwrapped(array $body): array
    {
        return is_array($body['data'] ?? null) ? $body['data'] : $body;
    }
}
