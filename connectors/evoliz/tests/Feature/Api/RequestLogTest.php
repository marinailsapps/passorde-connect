<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Passorde\Api\Contracts\RequestLog;
use Passorde\Api\DatabaseRequestLog;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\ForbiddenPathException;
use Passorde\Api\Exceptions\WritesDisabledException;
use Passorde\Api\NullRequestLog;
use Passorde\Api\RequestContext;
use Passorde\Api\RequestOutcome;
use Passorde\Api\RequestRunner;
use Passorde\Tests\Support\FakeEvoliz;

function migration(): object
{
    return require __DIR__.'/../../../../../database/migrations/2026_01_01_000000_create_passorde_requests_table.php';
}

function logRow(): object
{
    return DB::table('passorde_requests')->sole();
}

beforeEach(function () {
    FakeEvoliz::configure(writes: true);
    config()->set('passorde-connect.request_log.driver', 'database');
    migration()->up();
    Sleep::fake();
});

it('creates and drops the table with every column', function () {
    expect(Schema::getColumnListing('passorde_requests'))->toEqualCanonicalizing([
        'id', 'kind', 'method', 'path', 'payload', 'http_status', 'response', 'status', 'attempts',
        'last_error', 'subject_type', 'subject_id', 'created_at', 'updated_at',
    ]);

    migration()->down();

    expect(Schema::hasTable('passorde_requests'))->toBeFalse();
});

it('writes a row for a success', function () {
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients*' => Http::response(FakeEvoliz::fixture('client'), 201)]);

    $outcome = app(RequestRunner::class)->run('client.test', 'post', '/clients', [
        'query' => ['notify' => 0],
        'json' => ['name' => 'SOCIETE EXEMPLE'],
    ]);

    $row = logRow();

    expect($outcome->response->status())->toBe(201)
        ->and($row->kind)->toBe('client.test')
        ->and($row->method)->toBe('POST')
        ->and($row->path)->toBe('/clients?notify=0')
        ->and(json_decode($row->payload, true))->toBe(['name' => 'SOCIETE EXEMPLE'])
        ->and((int) $row->http_status)->toBe(201)
        ->and(json_decode($row->response, true)['clientid'])->toBe(501)
        ->and($row->status)->toBe('succeeded')
        ->and((int) $row->attempts)->toBe(1)
        ->and($row->last_error)->toBeNull()
        ->and($row->subject_type)->toBeNull()
        ->and($row->created_at)->not->toBeNull();
});

it('writes a row for a final failure', function () {
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response(FakeEvoliz::fixture('error-fields'), 400)]);

    expect(fn () => app(RequestRunner::class)->run('client.test', 'POST', '/clients', ['json' => ['name' => '']]))
        ->toThrow(ApiException::class, 'HTTP 400 — name :');

    $row = logRow();

    expect($row->status)->toBe('failed')
        ->and((int) $row->http_status)->toBe(400)
        ->and((int) $row->attempts)->toBe(1)
        ->and($row->last_error)->toStartWith('HTTP 400 — name :')
        ->and(json_decode($row->response, true)['message'])->toHaveKey('name');
});

it('keeps the row running while another attempt follows', function () {
    $during = [];
    FakeEvoliz::fake([
        FakeEvoliz::SCOPE.'/clients' => function () use (&$during) {
            $during[] = DB::table('passorde_requests')->first();

            return count($during) === 1 ? Http::response('', 503) : Http::response(FakeEvoliz::fixture('client'), 201);
        },
    ]);

    app(RequestRunner::class)->run('client.test', 'POST', '/clients');

    $row = logRow();

    expect($during[0]->status)->toBe('running')
        ->and((int) $during[0]->attempts)->toBe(0)
        ->and($during[1]->status)->toBe('running')
        ->and((int) $during[1]->attempts)->toBe(1)
        ->and((int) $during[1]->http_status)->toBe(503)
        ->and($during[1]->last_error)->toBe('HTTP 503')
        ->and($row->status)->toBe('succeeded')
        ->and((int) $row->attempts)->toBe(2)
        ->and($row->last_error)->toBeNull();
});

it('records attempts running out', function () {
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => fn () => throw new ConnectionException('cURL error 28')]);

    expect(fn () => app(RequestRunner::class)->run('client.test', 'POST', '/clients'))->toThrow(ApiException::class);

    $row = logRow();

    expect($row->status)->toBe('failed')
        ->and((int) $row->attempts)->toBe(5)
        ->and($row->http_status)->toBeNull()
        ->and($row->last_error)->toBe('Connexion à Evoliz impossible — cURL error 28');
    expect(count(FakeEvoliz::calls()))->toBe(0);
});

it('records a resource found by the lookup, without posting again', function () {
    $posts = 0;
    FakeEvoliz::fake([
        FakeEvoliz::SCOPE.'/clients' => function () use (&$posts) {
            $posts++;

            throw new ConnectionException('timeout');
        },
    ]);

    $outcome = app(RequestRunner::class)->run(
        'client.test',
        'POST',
        '/clients',
        ['json' => ['name' => 'SOCIETE EXEMPLE']],
        fn () => FakeEvoliz::fixture('client'),
    );

    $row = logRow();

    expect($posts)->toBe(1)
        ->and($outcome->foundByLookup())->toBeTrue()
        ->and($outcome->found['clientid'])->toBe(501)
        ->and($row->status)->toBe('succeeded')
        ->and((int) $row->attempts)->toBe(1)
        ->and($row->http_status)->toBeNull()
        ->and(json_decode($row->response, true)['clientid'])->toBe(501)
        ->and($row->last_error)->toContain('Retrouvé');
});

it('records a call the guards refused', function (array $config, string $path, string $exception) {
    config()->set($config);
    Http::fake();

    expect(fn () => app(RequestRunner::class)->run('client.test', 'POST', $path))->toThrow($exception);

    $row = logRow();

    expect($row->status)->toBe('failed')
        ->and((int) $row->attempts)->toBe(0)
        ->and($row->last_error)->not->toBeEmpty();
    Http::assertNothingSent();
})->with([
    'writes off' => [['passorde-connect.writes_enabled' => false], '/clients', WritesDisabledException::class],
    'forbidden' => [[], '/invoices', ForbiddenPathException::class],
]);

it('keeps the raw body of an answer that is not JSON, and truncates a huge one', function () {
    $log = new DatabaseRequestLog;

    $id = $log->start('k', 'GET', '/a?x=1', ['y' => 2]);
    $log->finish($id, 1, 500, '<html>', 'HTTP 500', RequestOutcome::Failed);

    expect(logRow()->path)->toBe('/a?x=1&y=2')
        ->and(json_decode(logRow()->response, true))->toBe(['raw' => '<html>']);

    $log->finish($id, 1, 200, ['big' => str_repeat('x', 100000)], null, RequestOutcome::Succeeded);

    $response = json_decode(logRow()->response, true);

    expect(strlen(logRow()->response))->toBeLessThan(70000)
        ->and($response['truncated'])->toBeTrue();
});

it('does not turn a success into a failure when the log cannot be written', function () {
    migration()->down();
    $warnings = 0;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings) {
        $warnings += $event->level === 'warning' ? 1 : 0;
    });
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response(FakeEvoliz::fixture('client'), 201)]);

    $outcome = app(RequestRunner::class)->run('client.test', 'POST', '/clients');

    expect($outcome->response->status())->toBe(201)
        ->and($warnings)->toBe(1);
});

it('does not fail when only the end of the log cannot be written', function () {
    app()->instance(RequestLog::class, new class implements RequestLog
    {
        public function start(string $kind, string $method, string $path, array $query = [], ?array $body = null): int
        {
            return 1;
        }

        public function finish(int|string $id, int $attempt, ?int $status, array|string|null $responseBody, ?string $error, RequestOutcome $outcome): void
        {
            throw new RuntimeException('Database gone');
        }
    });
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response(FakeEvoliz::fixture('client'), 201)]);

    expect(app(RequestRunner::class)->run('client.test', 'POST', '/clients')->response->status())->toBe(201);
});

it('writes nothing with the null log', function () {
    config()->set('passorde-connect.request_log.driver', 'null');
    app()->forgetInstance(RequestLog::class);
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response(FakeEvoliz::fixture('client'), 201)]);

    app(RequestRunner::class)->run('client.test', 'POST', '/clients');

    expect(app(RequestLog::class))->toBeInstanceOf(NullRequestLog::class)
        ->and(DB::table('passorde_requests')->count())->toBe(0);
});

it('lets a host bind its own log', function () {
    $own = new class implements RequestLog
    {
        public array $outcomes = [];

        public function start(string $kind, string $method, string $path, array $query = [], ?array $body = null): string
        {
            return 'own-id';
        }

        public function finish(int|string $id, int $attempt, ?int $status, array|string|null $responseBody, ?string $error, RequestOutcome $outcome): void
        {
            $this->outcomes[] = [$id, $attempt, $status, $outcome];
        }
    };
    app()->instance(RequestLog::class, $own);
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response(FakeEvoliz::fixture('client'), 201)]);

    app(RequestRunner::class)->run('client.test', 'POST', '/clients');

    expect($own->outcomes)->toBe([['own-id', 1, 201, RequestOutcome::Succeeded]]);
});

it('never writes the keys or the token to the table', function () {
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response(FakeEvoliz::fixture('client'), 201)]);

    app(RequestRunner::class)->run('client.test', 'POST', '/clients');

    $row = json_encode(logRow());

    expect($row)->not->toContain(FakeEvoliz::SECRET)
        ->and($row)->not->toContain(FakeEvoliz::PUBLIC_KEY)
        ->and($row)->not->toContain(FakeEvoliz::TOKEN);
});

it('tags every request made inside a subject, nested ones with the innermost, and clears it after', function () {
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients*' => Http::response(FakeEvoliz::fixture('client'), 201)]);
    $runner = app(RequestRunner::class);
    $call = fn (string $kind) => $runner->run($kind, 'POST', '/clients', ['json' => ['name' => 'X']]);

    $answer = RequestContext::subject('order', 42, function () use ($call) {
        $call('outer.first');
        RequestContext::subject('order-line', '7', fn () => $call('inner'));
        $call('outer.second');

        return 'done';
    });
    $call('outside');

    $subjects = DB::table('passorde_requests')->orderBy('id')->get()
        ->map(fn (object $row) => [$row->kind, $row->subject_type, $row->subject_id === null ? null : (int) $row->subject_id])
        ->all();

    expect($answer)->toBe('done')
        ->and($subjects)->toBe([
            ['outer.first', 'order', 42],
            ['inner', 'order-line', 7],
            ['outer.second', 'order', 42],
            ['outside', null, null],
        ])
        ->and(RequestContext::current())->toBeNull();
});

it('clears the subject when the work throws', function () {
    expect(fn () => RequestContext::subject('order', 1, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class, 'boom')
        ->and(RequestContext::current())->toBeNull();

    RequestContext::subject('order', 1, function () {
        try {
            RequestContext::subject('line', 2, fn () => throw new RuntimeException('inner'));
        } catch (RuntimeException) {
        }

        expect(RequestContext::current())->toBe(['type' => 'order', 'id' => 1]);
    });
});

it('refuses a subject it could not store', function (string $type, int|string $id) {
    expect(fn () => RequestContext::subject($type, $id, fn () => null))->toThrow(InvalidArgumentException::class)
        ->and(RequestContext::current())->toBeNull();
})->with([
    'empty type' => ['', 1],
    'zero' => ['order', 0],
    'negative' => ['order', -3],
    'uuid' => ['order', '9b2f4c1e-0000-4000-8000-000000000000'],
    'blank id' => ['order', ''],
]);

it('keeps the subject across the attempts of one request', function () {
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients*' => Http::sequence()->push(['message' => 'Server Error'], 500)->push(FakeEvoliz::fixture('client'), 201)]);

    RequestContext::subject('order', 9, fn () => app(RequestRunner::class)->run('client.test', 'POST', '/clients', ['json' => ['name' => 'X']], fn () => null));

    expect(logRow()->subject_type)->toBe('order')
        ->and((int) logRow()->subject_id)->toBe(9)
        ->and((int) logRow()->attempts)->toBe(2);
});
