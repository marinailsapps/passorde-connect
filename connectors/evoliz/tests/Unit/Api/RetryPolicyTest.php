<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Passorde\Api\ApiError;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\RetryPolicy;

function retryResponse(int $status, array $headers = [], string $body = '{}'): Response
{
    return new Response(new Psr7Response($status, $headers, $body));
}

/**
 * A call whose answers are responses to return or exceptions to throw.
 */
function scripted(array $answers): object
{
    return new class($answers)
    {
        public int $calls = 0;

        public function __construct(private array $answers) {}

        public function __invoke(): Response
        {
            $this->calls++;
            $answer = array_shift($this->answers);

            return $answer instanceof Throwable ? throw $answer : $answer;
        }
    };
}

beforeEach(function () {
    Sleep::fake();
    $this->slept = [];
    Sleep::whenFakingSleep(function ($duration) {
        $this->slept[] = (int) $duration->totalSeconds;
    });
});

it('returns the first success without waiting', function () {
    $call = scripted([retryResponse(201)]);
    $outcome = (new RetryPolicy)->run($call);

    expect($call->calls)->toBe(1)
        ->and($outcome->attempts)->toBe(1)
        ->and($outcome->response->status())->toBe(201)
        ->and($outcome->foundByLookup())->toBeFalse();
    Sleep::assertNeverSlept();
});

it('treats other statuses as final', function (int $status) {
    $call = scripted([retryResponse($status, body: '{"message":"Refused"}')]);

    try {
        (new RetryPolicy)->run($call);
        $this->fail('Expected an ApiException.');
    } catch (ApiException $exception) {
        expect($exception->error->status)->toBe($status)
            ->and($exception->getMessage())->toBe('HTTP '.$status.' — Refused');
    }

    expect($call->calls)->toBe(1);
    Sleep::assertNeverSlept();
})->with([400, 401, 403, 404, 422, 302]);

it('retries 5xx with a doubling delay, up to 5 attempts', function () {
    $call = scripted(array_fill(0, 5, retryResponse(503)));

    expect(fn () => (new RetryPolicy)->run($call))->toThrow(ApiException::class, 'HTTP 503');

    expect($call->calls)->toBe(5)
        ->and($this->slept)->toBe([5, 10, 20, 40]);
});

it('caps the backoff at 60 s', function () {
    $call = scripted(array_fill(0, 4, retryResponse(500)));

    expect(fn () => (new RetryPolicy(maxAttempts: 4, baseDelaySeconds: 40))->run($call))->toThrow(ApiException::class);

    expect($this->slept)->toBe([40, 60, 60]);
});

it('uses Retry-After on a 429, bounded, and the backoff without it', function () {
    $outcome = (new RetryPolicy)->run(scripted([
        retryResponse(429, ['Retry-After' => '7']),
        retryResponse(429, ['Retry-After' => '500']),
        retryResponse(429),
        retryResponse(200),
    ]));

    expect($outcome->attempts)->toBe(4)
        ->and($this->slept)->toBe([7, 60, 20]);
});

it('retries connection errors and reports them when they persist', function () {
    $call = scripted(array_fill(0, 5, new ConnectionException('cURL error 28')));

    try {
        (new RetryPolicy)->run($call);
        $this->fail('Expected an ApiException.');
    } catch (ApiException $exception) {
        expect($exception->error->status)->toBeNull()
            ->and($exception->getMessage())->toBe('Connexion à Evoliz impossible — cURL error 28');
    }

    expect($call->calls)->toBe(5);
});

it('succeeds after a connection error', function () {
    $outcome = (new RetryPolicy)->run(scripted([new ConnectionException('timeout'), retryResponse(200)]));

    expect($outcome->attempts)->toBe(2)
        ->and($outcome->response->status())->toBe(200);
});

it('returns what the lookup finds, after the wait, instead of posting again', function () {
    $call = scripted([new ConnectionException('timeout'), retryResponse(201)]);
    $sleptBeforeLookup = null;

    $outcome = (new RetryPolicy)->run($call, function () use (&$sleptBeforeLookup) {
        $sleptBeforeLookup = $this->slept;

        return ['clientid' => 501];
    });

    expect($call->calls)->toBe(1)
        ->and($sleptBeforeLookup)->toBe([5])
        ->and($outcome->foundByLookup())->toBeTrue()
        ->and($outcome->response)->toBeNull()
        ->and($outcome->found)->toBe(['clientid' => 501])
        ->and($outcome->attempts)->toBe(1);
});

it('posts again when the lookup finds nothing', function () {
    $call = scripted([retryResponse(500), retryResponse(201)]);
    $lookups = 0;

    $outcome = (new RetryPolicy)->run($call, function () use (&$lookups) {
        $lookups++;

        return null;
    });

    expect($call->calls)->toBe(2)
        ->and($lookups)->toBe(1)
        ->and($outcome->response->status())->toBe(201);
});

it('does not look up after a 429, which means nothing was done', function () {
    $call = scripted([retryResponse(429, ['Retry-After' => '1']), retryResponse(201)]);
    $lookups = 0;

    (new RetryPolicy)->run($call, function () use (&$lookups) {
        $lookups++;
    });

    expect($lookups)->toBe(0)->and($call->calls)->toBe(2);
});

it('does not post again while the lookup cannot answer', function () {
    $call = scripted([new ConnectionException('timeout'), retryResponse(201)]);
    $lookups = 0;

    expect(function () use ($call, &$lookups) {
        (new RetryPolicy)->run($call, function () use (&$lookups) {
            $lookups++;

            throw new ConnectionException('still down');
        });
    })->toThrow(ApiException::class, 'timeout');

    expect($call->calls)->toBe(1)
        ->and($lookups)->toBe(4);
});

it('resumes once a failing lookup answers', function () {
    $call = scripted([retryResponse(502), retryResponse(201)]);
    $lookups = 0;

    $outcome = (new RetryPolicy)->run($call, function () use (&$lookups) {
        if (++$lookups === 1) {
            throw new ApiException(new ApiError(503, 'HTTP 503'));
        }

        return null;
    });

    expect($call->calls)->toBe(2)
        ->and($lookups)->toBe(2)
        ->and($outcome->response->status())->toBe(201)
        ->and($this->slept)->toBe([5, 10]);
});

it('stops when the lookup fails for good', function () {
    $call = scripted([retryResponse(500)]);

    expect(fn () => (new RetryPolicy)->run(
        $call,
        fn () => throw new ApiException(new ApiError(403, 'HTTP 403 — no')),
    ))->toThrow(ApiException::class, 'HTTP 403 — no');

    expect($call->calls)->toBe(1);
});

it('retries a failed login that can be retried, without a lookup', function () {
    $lookups = 0;

    $outcome = (new RetryPolicy)->run(
        scripted([new ApiException(new ApiError(503, 'HTTP 503')), retryResponse(200)]),
        function () use (&$lookups) {
            $lookups++;
        },
    );

    expect($outcome->attempts)->toBe(2)->and($lookups)->toBe(0);
});

it('reports every attempt', function () {
    $reports = [];

    (new RetryPolicy)->run(
        scripted([retryResponse(503), new ConnectionException('timeout'), retryResponse(200)]),
        onAttempt: function (int $call, ?Response $response, ?string $error, bool $willRetry) use (&$reports) {
            $reports[] = [$call, $response?->status(), $error, $willRetry];
        },
    );

    expect($reports)->toBe([
        [1, 503, 'HTTP 503 — {}', true],
        [2, null, 'Connexion à Evoliz impossible — timeout', true],
        [3, 200, null, false],
    ]);
});

it('lets other exceptions through at once', function () {
    $call = scripted([new LogicException('guard')]);

    expect(fn () => (new RetryPolicy)->run($call))->toThrow(LogicException::class);

    expect($call->calls)->toBe(1);
    Sleep::assertNeverSlept();
});

it('looks up one last time when the attempts run out', function () {
    Sleep::fake();
    $lookups = 0;

    $outcome = (new RetryPolicy(maxAttempts: 2))->run(
        fn () => throw new ConnectionException('timed out'),
        function () use (&$lookups) {
            return ++$lookups === 2 ? ['found' => true] : null;
        },
    );

    expect($outcome->foundByLookup())->toBeTrue()->and($outcome->attempts)->toBe(2)->and($lookups)->toBe(2);
});

it('keeps the error of the call when that last lookup fails too', function () {
    Sleep::fake();

    expect(fn () => (new RetryPolicy(maxAttempts: 1))->run(
        fn () => throw new ConnectionException('timed out'),
        fn () => throw new ConnectionException('still down'),
    ))->toThrow(ApiException::class, 'timed out');
});
