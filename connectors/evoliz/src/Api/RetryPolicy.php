<?php

namespace Passorde\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Passorde\Api\Exceptions\ApiException;

/**
 * Retries 429, 5xx and connection errors with a growing delay; any other status is final.
 */
final class RetryPolicy
{
    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $baseDelaySeconds = 5,
        private readonly int $maxDelaySeconds = 60,
    ) {}

    /**
     * After a connection error or a 5xx the call may have gone through, so $lookup runs before it is made
     * again, and once more when the attempts run out: a non-null result ends the run. A lookup that fails on its
     * own also uses up an attempt, and the call is not repeated until a lookup has answered, which is what keeps
     * a POST from creating a duplicate.
     *
     * @param  callable(): Response  $call
     * @param  (callable(): mixed)|null  $lookup
     * @param  (callable(int, Response|null, string|null, bool): void)|null  $onAttempt  Called after every call with the call number, the response, the error and whether another call follows.
     *
     * @throws ApiException When the error is final or the attempts have run out.
     */
    public function run(callable $call, ?callable $lookup = null, ?callable $onAttempt = null): RetryOutcome
    {
        $used = 0;
        $calls = 0;

        while (true) {
            $used++;
            $calls++;

            [$response, $error, $mayHaveSucceeded] = $this->attempt($call);

            if ($error === null) {
                if ($onAttempt !== null) {
                    $onAttempt($calls, $response, null, false);
                }

                return new RetryOutcome($response, null, $calls);
            }

            $willRetry = $error->isRetryable() && $used < $this->maxAttempts;

            if ($onAttempt !== null) {
                $onAttempt($calls, $response, $error->message, $willRetry);
            }

            if (! $willRetry) {
                $found = $error->isRetryable() && $mayHaveSucceeded ? $this->lastLookup($lookup) : null;

                if ($found !== null) {
                    return new RetryOutcome(null, $found, $calls);
                }

                throw new ApiException($error);
            }

            Sleep::for($this->delay($used, $error))->seconds();

            while ($lookup !== null && $mayHaveSucceeded) {
                try {
                    $found = $lookup();
                } catch (ConnectionException|ApiException $exception) {
                    if ($exception instanceof ApiException && ! $exception->error->isRetryable()) {
                        throw $exception;
                    }

                    $used++;

                    if ($used >= $this->maxAttempts) {
                        throw new ApiException($error);
                    }

                    Sleep::for($this->delay($used, null))->seconds();

                    continue;
                }

                if ($found !== null) {
                    return new RetryOutcome(null, $found, $calls);
                }

                break;
            }
        }
    }

    /**
     * @param  (callable(): mixed)|null  $lookup
     */
    private function lastLookup(?callable $lookup): mixed
    {
        if ($lookup === null) {
            return null;
        }

        try {
            return $lookup();
        } catch (ConnectionException|ApiException) {
            return null;
        }
    }

    /**
     * @param  callable(): Response  $call
     * @return array{0: Response|null, 1: ApiError|null, 2: bool}
     */
    private function attempt(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $exception) {
            return [null, ApiError::connectionFailure($exception->getMessage()), true];
        } catch (ApiException $exception) {
            // The login failed: the call itself never went out.
            return [null, $exception->error, false];
        }

        if ($response->successful()) {
            return [$response, null, false];
        }

        return [$response, ApiError::fromResponse($response), $response->serverError()];
    }

    private function delay(int $used, ?ApiError $error): int
    {
        if ($error?->status === 429 && $error->retryAfter !== null) {
            return max(1, min($error->retryAfter, $this->maxDelaySeconds));
        }

        return min($this->baseDelaySeconds * 2 ** ($used - 1), $this->maxDelaySeconds);
    }
}
