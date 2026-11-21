<?php

namespace Passorde\Api;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\Contracts\RequestLog;
use Throwable;

/**
 * One call as the business verbs make it: retried by the policy and written to the request log, secrets masked.
 */
final class RequestRunner
{
    public function __construct(
        private readonly EvolizApi $api,
        private readonly RetryPolicy $policy,
        private readonly RequestLog $log,
    ) {}

    /**
     * @param  string  $kind  What the call is for, as recorded in the log.
     * @param  array{query?: array<string, mixed>, json?: array<array-key, mixed>, timeout?: int}  $options
     * @param  (callable(): mixed)|null  $lookup  See RetryPolicy::run().
     *
     * @throws Throwable Whatever the policy or the guards throw; the log row records it first.
     */
    public function run(string $kind, string $method, string $path, array $options = [], ?callable $lookup = null): RetryOutcome
    {
        $id = $this->record(fn () => $this->log->start(
            $kind,
            strtoupper($method),
            SecretMask::text($path),
            SecretMask::values($options['query'] ?? []),
            isset($options['json']) ? SecretMask::values($options['json']) : null,
        ));
        $attempt = 0;
        $status = null;
        $body = null;

        try {
            $outcome = $this->policy->run(
                fn (): Response => $this->api->send($method, $path, $options),
                $lookup,
                function (int $call, ?Response $response, ?string $error, bool $willRetry) use ($id, &$attempt, &$status, &$body): void {
                    $attempt = $call;
                    $status = $response?->status();
                    $body = $response === null ? null : $this->bodyOf($response);

                    $this->finish($id, $attempt, $status, $body, $error, match (true) {
                        $willRetry => RequestOutcome::Running,
                        $error === null => RequestOutcome::Succeeded,
                        default => RequestOutcome::Failed,
                    });
                },
            );
        } catch (Throwable $exception) {
            $this->finish($id, $attempt, $status, $body, $exception->getMessage(), RequestOutcome::Failed);

            throw $exception;
        }

        if ($outcome->foundByLookup()) {
            $found = is_array($outcome->found) ? $outcome->found : null;

            $this->finish($id, $outcome->attempts, null, $found, 'Retrouvé par recherche après une réponse incertaine', RequestOutcome::Succeeded);
        }

        return $outcome;
    }

    /**
     * @return array<array-key, mixed>|string
     */
    private function bodyOf(Response $response): array|string
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : $response->body();
    }

    /**
     * @param  array<array-key, mixed>|string|null  $body
     */
    private function finish(int|string|null $id, int $attempt, ?int $status, array|string|null $body, ?string $error, RequestOutcome $outcome): void
    {
        if ($id === null) {
            return;
        }

        $body = is_array($body) ? SecretMask::values($body) : ($body === null ? null : SecretMask::text($body));
        $error = $error === null ? null : SecretMask::text($error);

        $this->record(fn () => $this->log->finish($id, $attempt, $status, $body, $error, $outcome));
    }

    /**
     * A log that cannot be written must not turn a POST that succeeded into a failure, which would get it posted again.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T|null
     */
    private function record(callable $write): mixed
    {
        try {
            return $write();
        } catch (Throwable $exception) {
            Log::warning('Passorde: request log could not be written.', ['error' => $exception->getMessage()]);

            return null;
        }
    }
}
