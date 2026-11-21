<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Passorde\Api\ApiError;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Tests\Support\FakeEvoliz;

function errorResponse(int $status, string $body, array $headers = []): Response
{
    return new Response(new Psr7Response($status, $headers, $body));
}

it('reads a message that is a string', function () {
    $error = ApiError::fromResponse(errorResponse(401, json_encode(FakeEvoliz::fixture('error-message'))));

    expect($error->status)->toBe(401)
        ->and($error->message)->toBe('HTTP 401 — Unauthenticated.')
        ->and($error->fieldErrors)->toBe([]);
});

it('reads a message that is a map of fields', function () {
    $error = ApiError::fromResponse(errorResponse(400, json_encode(FakeEvoliz::fixture('error-fields'))));

    expect($error->message)->toBe('HTTP 400 — name : The name field is required. ; address.postcode : The postcode is invalid., The postcode is too short.')
        ->and($error->fieldErrors)->toBe([
            'name' => ['The name field is required.'],
            'address.postcode' => ['The postcode is invalid.', 'The postcode is too short.'],
        ]);
});

it('falls back on error', function () {
    $error = ApiError::fromResponse(errorResponse(404, json_encode(FakeEvoliz::fixture('error-error'))));

    expect($error->message)->toBe('HTTP 404 — Company not found');
});

it('falls back on the raw body, on one line and truncated', function () {
    $error = ApiError::fromResponse(errorResponse(502, "<html>\n  ".str_repeat('x', 500)."\n</html>"));

    expect($error->message)->toStartWith('HTTP 502 — <html> xxx')
        ->and($error->message)->toEndWith('…')
        ->and($error->message)->not->toContain("\n")
        ->and(mb_strlen($error->message))->toBeLessThan(220);
});

it('falls back on the raw JSON when it holds neither message nor error', function () {
    expect(ApiError::fromResponse(errorResponse(500, '{"code":17}'))->message)->toBe('HTTP 500 — {"code":17}');
});

it('accepts a message that is a plain list, an empty message and an empty body', function () {
    expect(ApiError::fromResponse(errorResponse(400, '{"message":["one","two"]}'))->message)->toBe('HTTP 400 — one ; two')
        ->and(ApiError::fromResponse(errorResponse(400, '{"message":["one","two"]}'))->fieldErrors)->toBe([])
        ->and(ApiError::fromResponse(errorResponse(400, '{"message":"","error":"bad"}'))->message)->toBe('HTTP 400 — bad')
        ->and(ApiError::fromResponse(errorResponse(500, ''))->message)->toBe('HTTP 500');
});

it('keeps a field whose messages are a plain string', function () {
    $error = ApiError::fromResponse(errorResponse(400, '{"message":{"name":"Required"}}'));

    expect($error->fieldErrors)->toBe(['name' => ['Required']])
        ->and($error->message)->toBe('HTTP 400 — name : Required');
});

it('reads Retry-After in seconds or as a date', function () {
    expect(ApiError::fromResponse(errorResponse(429, '', ['Retry-After' => '17']))->retryAfter)->toBe(17)
        ->and(ApiError::fromResponse(errorResponse(429, '', ['Retry-After' => now()->addSeconds(30)->toRfc7231String()]))->retryAfter)->toBe(30)
        ->and(ApiError::fromResponse(errorResponse(429, '', ['Retry-After' => 'soon']))->retryAfter)->toBeNull();
});

it('tells which errors can be retried', function (?int $status, bool $retryable) {
    expect((new ApiError($status, 'x'))->isRetryable())->toBe($retryable);
})->with([
    [null, true], [429, true], [500, true], [503, true],
    [400, false], [401, false], [404, false], [422, false],
]);

it('is wrapped by ApiException', function () {
    $exception = new ApiException(new ApiError(400, 'HTTP 400 — x'));

    expect($exception->getMessage())->toBe('HTTP 400 — x')
        ->and($exception->error->status)->toBe(400);
});
