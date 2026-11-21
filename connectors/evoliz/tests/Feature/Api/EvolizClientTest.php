<?php

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\Credentials;
use Passorde\Api\EvolizToken;
use Passorde\Api\Exceptions\ApiConfigurationException;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\ForbiddenPathException;
use Passorde\Api\Exceptions\InvalidPathException;
use Passorde\Api\Exceptions\PaginationLimitException;
use Passorde\Api\Exceptions\WritesDisabledException;
use Passorde\Api\RateLimitMonitor;
use Passorde\Licence\Exceptions\LicenceExpiredException;
use Passorde\Licence\Exceptions\LicenceInvalidException;
use Passorde\Licence\LicenceManager;
use Passorde\Tests\Support\FakeEvoliz;
use Passorde\Tests\Support\TestLicence;

function api(): EvolizApi
{
    return app(EvolizApi::class);
}

/**
 * @return list<array{level: string, message: string, context: array<string, mixed>}>
 */
function captureLogs(): ArrayObject
{
    $logs = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($logs) {
        $logs[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
    });

    return $logs;
}

/**
 * The strings a logger could print from the arguments of a trace. Objects are left out:
 * they hold the whole test framework, and Credentials has its own test.
 *
 * @return list<string>
 */
function traceScalars(Throwable $exception): array
{
    $strings = [];

    $trace = $exception->getTrace();

    array_walk_recursive($trace, function (mixed $value) use (&$strings) {
        if (is_string($value)) {
            $strings[] = $value;
        }
    });

    return $strings;
}

beforeEach(function () {
    FakeEvoliz::configure(writes: true);
    Sleep::fake();
});

describe('login and token', function () {
    it('logs in with the keys as JSON and sends the token as a bearer', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/vat-rates*' => Http::response(FakeEvoliz::fixture('vat-rates'))]);

        $body = api()->get('/vat-rates', ['per_page' => 1]);

        expect($body['data'][0]['rate'])->toBe(20);

        Http::assertSent(fn (Request $request) => $request->url() === FakeEvoliz::BASE.'/api/login'
            && $request->method() === 'POST'
            && $request->data() === ['public_key' => FakeEvoliz::PUBLIC_KEY, 'secret_key' => FakeEvoliz::SECRET]
            && $request->hasHeader('Accept', 'application/json')
            && ! $request->hasHeader('Authorization'));

        Http::assertSent(fn (Request $request) => $request->url() === FakeEvoliz::BASE.'/api/v1/companies/12345/vat-rates?per_page=1'
            && $request->hasHeader('Authorization', 'Bearer '.FakeEvoliz::TOKEN)
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Content-Type', 'application/json'));
    });

    it('caches the token for about 19 minutes', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/vat-rates*' => Http::response(FakeEvoliz::fixture('vat-rates'))]);

        api()->get('/vat-rates');
        api()->get('/vat-rates');
        expect(FakeEvoliz::logins())->toBe(1);

        Carbon::setTestNow(Carbon::now()->addSeconds(18 * 60 + 59));
        api()->get('/vat-rates');
        expect(FakeEvoliz::logins())->toBe(1)
            ->and(app(EvolizToken::class)->ageSeconds())->toBe(18 * 60 + 59);

        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        api()->get('/vat-rates');
        expect(FakeEvoliz::logins())->toBe(2)
            ->and(app(EvolizToken::class)->ageSeconds())->toBe(0);
    });

    it('uses the configured cache store', function () {
        config()->set('cache.stores.passorde_test', ['driver' => 'array']);
        config()->set('passorde-connect.cache_store', 'passorde_test');
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/vat-rates*' => Http::response(FakeEvoliz::fixture('vat-rates'))]);

        api()->get('/vat-rates');

        $key = 'passorde:evoliz:token:'.hash('sha256', FakeEvoliz::BASE.'|'.FakeEvoliz::PUBLIC_KEY);

        expect(Cache::store('passorde_test')->has($key))->toBeTrue()
            ->and(Cache::store('array')->has($key))->toBeFalse();
    });

    it('logs in again once on a 401 and retries', function () {
        FakeEvoliz::fake([
            FakeEvoliz::SCOPE.'/vat-rates*' => Http::sequence()
                ->push(FakeEvoliz::fixture('error-message'), 401)
                ->push(FakeEvoliz::fixture('vat-rates')),
        ]);

        expect(api()->get('/vat-rates')['data'])->toHaveCount(1)
            ->and(FakeEvoliz::logins())->toBe(2)
            ->and(FakeEvoliz::calls())->toHaveCount(2);
    });

    it('gives up after the second 401, and forgets the token', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/vat-rates*' => Http::response(FakeEvoliz::fixture('error-message'), 401)]);

        expect(api()->send('GET', '/vat-rates')->status())->toBe(401)
            ->and(FakeEvoliz::logins())->toBe(2)
            ->and(FakeEvoliz::calls())->toHaveCount(2)
            ->and(app(EvolizToken::class)->ageSeconds())->toBeNull();
    });

    it('reports a refused login', function () {
        Http::fake([FakeEvoliz::LOGIN => Http::response(FakeEvoliz::fixture('error-message'), 401)]);

        expect(fn () => api()->get('/vat-rates'))->toThrow(ApiException::class, 'HTTP 401 — Unauthenticated.');
        expect(FakeEvoliz::calls())->toBe([]);
    });

    it('reports a login answer without a token', function () {
        Http::fake([FakeEvoliz::LOGIN => Http::response(['expires_at' => 'x'])]);

        expect(fn () => api()->get('/vat-rates'))->toThrow(ApiException::class, 'access_token');
    });

    it('waits and tries again when the login gets a 429', function () {
        Http::fake([
            FakeEvoliz::LOGIN => Http::sequence()
                ->push([], 429, ['Retry-After' => '3'])
                ->push(FakeEvoliz::fixture('login')),
            FakeEvoliz::SCOPE.'/vat-rates*' => Http::response(FakeEvoliz::fixture('vat-rates')),
        ]);

        api()->get('/vat-rates');

        expect(FakeEvoliz::logins())->toBe(2);
        Sleep::assertSleptTimes(1);
    });

    it('names the settings that are missing', function () {
        config()->set('passorde-connect.evoliz.company_id', null);
        config()->set('passorde-connect.evoliz.secret_key', '');
        Http::fake();

        expect(fn () => api()->get('/vat-rates'))->toThrow(ApiConfigurationException::class, 'PASSORDE_EVOLIZ_COMPANY_ID, PASSORDE_EVOLIZ_SECRET_KEY');
        Http::assertNothingSent();
    });
});

describe('secrets', function () {
    it('never logs the secret key or the token', function () {
        $logs = captureLogs();

        FakeEvoliz::fake([
            FakeEvoliz::SCOPE.'/vat-rates*' => Http::sequence()
                ->push(FakeEvoliz::fixture('error-message'), 401)
                ->push([], 429, ['X-RateLimit-Limit' => '100', 'X-RateLimit-Remaining' => '0', 'Retry-After' => '1'])
                ->push(FakeEvoliz::fixture('vat-rates'), 200, ['X-RateLimit-Limit' => '100', 'X-RateLimit-Remaining' => '5']),
        ]);

        api()->get('/vat-rates');

        $written = json_encode($logs->getArrayCopy());

        expect(count($logs))->toBeGreaterThan(0)
            ->and($written)->not->toContain(FakeEvoliz::SECRET)
            ->and($written)->not->toContain(FakeEvoliz::TOKEN);
    });

    it('keeps the secret out of the trace of a failed login', function () {
        ini_set('zend.exception_ignore_args', '0');
        Http::fake([FakeEvoliz::LOGIN => fn () => throw new ConnectionException('cURL error 28')]);

        try {
            api()->get('/vat-rates');
            $this->fail('Expected a ConnectionException.');
        } catch (ConnectionException $exception) {
            expect($exception->getMessage())->toBe('cURL error 28')
                ->and($exception->getPrevious())->toBeNull()
                ->and(traceScalars($exception))->not->toContain(FakeEvoliz::SECRET);
        }
    });

    it('hides the secret from dumps and marks it as sensitive', function () {
        $credentials = Credentials::fromConfig();
        $parameter = (new ReflectionMethod(Credentials::class, '__construct'))->getParameters()[3];

        expect(print_r($credentials, true))->not->toContain(FakeEvoliz::SECRET)
            ->and($credentials->secretKey())->toBe(FakeEvoliz::SECRET)
            ->and($parameter->getName())->toBe('secretKey')
            ->and($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    });
});

describe('company scoping', function () {
    it('scopes relative paths to the company and lets /api/ paths through', function () {
        FakeEvoliz::fake(['*' => Http::response(['ok' => true])]);

        api()->send('GET', 'clients');
        api()->send('GET', '/articles/5');
        api()->send('GET', '/api/v1/companies/777/clients');
        api()->send('GET', '/api/v1/other');

        $urls = array_map(fn (Request $request) => $request->url(), FakeEvoliz::calls());

        expect($urls)->toBe([
            FakeEvoliz::BASE.'/api/v1/companies/12345/clients',
            FakeEvoliz::BASE.'/api/v1/companies/12345/articles/5',
            FakeEvoliz::BASE.'/api/v1/companies/777/clients',
            FakeEvoliz::BASE.'/api/v1/other',
        ]);
    });

    it('merges a query string from the path with the query option', function () {
        FakeEvoliz::fake(['*' => Http::response([])]);

        api()->send('GET', '/clients?page=2&per_page=5', ['query' => ['per_page' => 10]]);

        expect(FakeEvoliz::calls()[0]->url())->toBe(FakeEvoliz::BASE.'/api/v1/companies/12345/clients?page=2&per_page=10');
    });

    it('sends the JSON body, and no body without one', function () {
        FakeEvoliz::fake(['*' => Http::response(FakeEvoliz::fixture('client'), 201)]);

        expect(api()->send('post', '/clients', ['json' => ['name' => 'SOCIETE EXEMPLE']])->status())->toBe(201);
        api()->send('DELETE', '/clients/501');

        [$post, $delete] = FakeEvoliz::calls();

        expect($post->method())->toBe('POST')
            ->and($post->data())->toBe(['name' => 'SOCIETE EXEMPLE'])
            ->and($delete->body())->toBe('');
    });

    it('refuses a URL or an escape from the company before anything is sent', function (string $path) {
        Http::fake();

        expect(fn () => api()->get($path))->toThrow(InvalidPathException::class);
        Http::assertNothingSent();
    })->with(['https://elsewhere.test/clients', '/clients/../../999/clients']);

    it('does not follow redirects', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response('', 302, ['Location' => 'https://elsewhere.test/'])]);

        expect(api()->send('GET', '/clients')->status())->toBe(302)
            ->and(FakeEvoliz::calls())->toHaveCount(1);
    });

    it('sets the timeouts from config', function () {
        $options = [];
        config()->set('passorde-connect.evoliz.timeout', 12);
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options[] = $sent;

            return Http::response(FakeEvoliz::fixture('login'));
        });

        api()->send('GET', '/clients');

        expect(end($options)['timeout'])->toBe(12)
            ->and(end($options)['connect_timeout'])->toBe(10);
    });
});

describe('send and get', function () {
    it('returns 4xx and 5xx from send without throwing', function (int $status) {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response(['message' => 'No'], $status)]);

        expect(api()->send('GET', '/clients')->status())->toBe($status);
    })->with([400, 404, 500, 503]);

    it('throws ApiException from get on an error', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response(FakeEvoliz::fixture('error-fields'), 400)]);

        try {
            api()->get('/clients');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            expect($exception->error->status)->toBe(400)
                ->and($exception->error->fieldErrors)->toHaveKey('name');
        }
    });

    it('throws ApiException from get when the answer is not JSON', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response('hello')]);

        expect(fn () => api()->get('/clients'))->toThrow(ApiException::class, 'JSON');
    });

    it('lets a connection error through', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => fn () => throw new ConnectionException('timeout')]);

        expect(fn () => api()->send('GET', '/clients'))->toThrow(ConnectionException::class);
    });
});

describe('rate limit', function () {
    it('waits Retry-After on a 429 and retries once', function () {
        FakeEvoliz::fake([
            FakeEvoliz::SCOPE.'/clients' => Http::sequence()
                ->push([], 429, ['Retry-After' => '7'])
                ->push(['data' => []]),
        ]);

        expect(api()->send('GET', '/clients')->status())->toBe(200)
            ->and(FakeEvoliz::calls())->toHaveCount(2);
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
    });

    it('returns the second 429', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response([], 429, ['Retry-After' => '1'])]);

        expect(api()->send('GET', '/clients')->status())->toBe(429)
            ->and(FakeEvoliz::calls())->toHaveCount(2);
        Sleep::assertSleptTimes(1);
    });

    it('waits 60 s without Retry-After, and never longer', function (array $headers) {
        FakeEvoliz::fake([
            FakeEvoliz::SCOPE.'/clients' => Http::sequence()->push([], 429, $headers)->push([]),
        ]);

        api()->send('GET', '/clients');

        Sleep::assertSequence([Sleep::for(60)->seconds()]);
    })->with([
        'no header' => [[]],
        'unreadable header' => [['Retry-After' => 'soon']],
        'too long' => [['Retry-After' => '3600']],
    ]);

    it('waits at least a second when Retry-After says 0', function () {
        FakeEvoliz::fake([
            FakeEvoliz::SCOPE.'/clients' => Http::sequence()->push([], 429, ['Retry-After' => '0'])->push([]),
        ]);

        api()->send('GET', '/clients');

        Sleep::assertSequence([Sleep::for(1)->seconds()]);
    });

    it('pauses 60 s when fewer than 10 calls remain, with a warning', function () {
        $logs = captureLogs();
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response([], 200, ['X-RateLimit-Limit' => '100', 'X-RateLimit-Remaining' => '9'])]);

        api()->send('GET', '/clients');

        Sleep::assertSequence([Sleep::for(60)->seconds()]);

        $warnings = array_values(array_filter($logs->getArrayCopy(), fn (array $log) => $log['level'] === 'warning'));

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]['context']['remaining'])->toBe(9)
            ->and(app(RateLimitMonitor::class)->remaining())->toBe(9);
    });

    it('does not pause with 10 calls left, and logs the state at debug', function () {
        $logs = captureLogs();
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response([], 200, ['X-RateLimit-Limit' => '100', 'X-RateLimit-Remaining' => '10'])]);

        api()->send('GET', '/clients');

        Sleep::assertNeverSlept();

        $levels = array_column($logs->getArrayCopy(), 'level');

        expect($levels)->toBe(['debug'])
            ->and($logs[0]['context'])->toBe(['limit' => 100, 'remaining' => 10]);
    });

    it('does nothing without the header', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response([])]);

        api()->send('GET', '/clients');

        Sleep::assertNeverSlept();
        expect(app(RateLimitMonitor::class)->remaining())->toBeNull();
    });

    it('does not add the low-remaining pause to the wait of a 429', function () {
        FakeEvoliz::fake([
            FakeEvoliz::SCOPE.'/clients' => Http::sequence()
                ->push([], 429, ['X-RateLimit-Remaining' => '0', 'Retry-After' => '5'])
                ->push([], 200, ['X-RateLimit-Remaining' => '99']),
        ]);

        api()->send('GET', '/clients');

        Sleep::assertSequence([Sleep::for(5)->seconds()]);
    });

    it('also watches the login response', function () {
        Http::fake([
            FakeEvoliz::LOGIN => Http::response(FakeEvoliz::fixture('login'), 200, ['X-RateLimit-Remaining' => '3']),
            FakeEvoliz::SCOPE.'/clients' => Http::response([], 200, ['X-RateLimit-Remaining' => '50']),
        ]);

        api()->send('GET', '/clients');

        Sleep::assertSleptTimes(1);
        expect(app(RateLimitMonitor::class)->remaining())->toBe(50);
    });
});

describe('pagination', function () {
    it('yields one array of rows per page and stops at last_page', function () {
        FakeEvoliz::fake([
            FakeEvoliz::SCOPE.'/articles*' => Http::sequence()
                ->push(FakeEvoliz::fixture('articles-page-1'))
                ->push(FakeEvoliz::fixture('articles-page-2')),
        ]);

        $pages = iterator_to_array(api()->paginate('/articles', ['enabled' => 1]), false);

        expect($pages)->toHaveCount(2)
            ->and(array_column($pages[0], 'reference'))->toBe(['REF-001', 'REF-002'])
            ->and(array_column($pages[1], 'reference'))->toBe(['REF-003']);

        $urls = array_map(fn (Request $request) => $request->url(), FakeEvoliz::calls());

        expect($urls)->toBe([
            FakeEvoliz::BASE.'/api/v1/companies/12345/articles?enabled=1&page=1&per_page=100',
            FakeEvoliz::BASE.'/api/v1/companies/12345/articles?enabled=1&page=2&per_page=100',
        ]);
    });

    it('is lazy', function () {
        FakeEvoliz::fake([
            FakeEvoliz::SCOPE.'/articles*' => Http::sequence()
                ->push(FakeEvoliz::fixture('articles-page-1'))
                ->push(FakeEvoliz::fixture('articles-page-2')),
        ]);

        $pages = api()->paginate('/articles');
        expect(FakeEvoliz::calls())->toHaveCount(0);

        $pages->current();
        expect(FakeEvoliz::calls())->toHaveCount(1);
    });

    it('keeps per_page within 1 and 100, and starts from a given page', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/articles*' => Http::response(['data' => [['id' => 1]], 'meta' => ['last_page' => 3]])]);

        iterator_to_array(api()->paginate('/articles', ['page' => 3], 500), false);
        iterator_to_array(api()->paginate('/articles', ['page' => 3], 0), false);

        $urls = array_map(fn (Request $request) => $request->url(), FakeEvoliz::calls());

        expect($urls[0])->toEndWith('?page=3&per_page=100')
            ->and($urls[1])->toEndWith('?page=3&per_page=1');
    });

    it('stops after one page without meta, and on an empty page', function () {
        FakeEvoliz::fake([
            FakeEvoliz::SCOPE.'/a*' => Http::response(['data' => [['id' => 1]]]),
            FakeEvoliz::SCOPE.'/b*' => Http::response(['data' => [], 'meta' => ['last_page' => 9]]),
        ]);

        expect(iterator_to_array(api()->paginate('/a'), false))->toBe([[['id' => 1]]])
            ->and(iterator_to_array(api()->paginate('/b'), false))->toBe([[]])
            ->and(FakeEvoliz::calls())->toHaveCount(2);
    });

    it('throws at the hard cap rather than passing off a partial list', function () {
        config()->set('passorde-connect.evoliz.max_pages', 3);
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/articles*' => Http::response(['data' => [['id' => 1]], 'meta' => ['last_page' => 999]])]);

        $received = 0;

        try {
            foreach (api()->paginate('/articles') as $page) {
                $received++;
            }
            $this->fail('Expected a PaginationLimitException.');
        } catch (PaginationLimitException $exception) {
            expect($exception->maxPages)->toBe(3);
        }

        expect($received)->toBe(3)
            ->and(FakeEvoliz::calls())->toHaveCount(3);
    });

    it('does not throw when the last page is exactly the cap', function () {
        config()->set('passorde-connect.evoliz.max_pages', 2);
        FakeEvoliz::fake([
            FakeEvoliz::SCOPE.'/articles*' => Http::sequence()
                ->push(FakeEvoliz::fixture('articles-page-1'))
                ->push(FakeEvoliz::fixture('articles-page-2')),
        ]);

        expect(iterator_to_array(api()->paginate('/articles'), false))->toHaveCount(2);
    });

    it('throws ApiException when a page fails', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/articles*' => Http::response(['message' => 'Broken'], 500)]);

        expect(fn () => iterator_to_array(api()->paginate('/articles')))->toThrow(ApiException::class, 'HTTP 500 — Broken');
    });
});

describe('guards', function () {
    it('refuses every write while writes are disabled, before any login', function (string $method) {
        config()->set('passorde-connect.writes_enabled', false);
        Http::fake();

        try {
            api()->send($method, '/clients', ['json' => ['name' => 'SOCIETE EXEMPLE']]);
            $this->fail('Expected a WritesDisabledException.');
        } catch (WritesDisabledException $exception) {
            expect($exception->method)->toBe(strtoupper($method))
                ->and($exception->path)->toBe('/clients');
        }

        Http::assertNothingSent();
    })->with(['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'post']);

    it('still reads while writes are disabled', function () {
        config()->set('passorde-connect.writes_enabled', false);
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response(['data' => []])]);

        expect(api()->get('/clients'))->toBe(['data' => []]);
    });

    it('defaults to writes being refused', function () {
        config()->set('passorde-connect.writes_enabled', filter_var(null, FILTER_VALIDATE_BOOL));
        Http::fake();

        expect(fn () => api()->send('POST', '/clients'))->toThrow(WritesDisabledException::class);
    });

    it('never calls a forbidden path, even with writes on and for a read', function (string $method, string $path) {
        Http::fake();

        try {
            api()->send($method, $path);
            $this->fail('Expected a ForbiddenPathException.');
        } catch (ForbiddenPathException $exception) {
            expect($exception->method)->toBe($method)
                ->and($exception->path)->toBe($path);
        }

        Http::assertNothingSent();
    })->with([
        ['GET', '/invoices'],
        ['POST', '/invoices'],
        ['POST', 'invoices/12/payments'],
        ['POST', '/deliveries/7/invoice'],
        ['GET', '/api/v1/companies/12345/invoices'],
        ['POST', '/api/v1/companies/12345/deliveries/7/invoice'],
        ['GET', '/%69nvoices?page=1'],
    ]);

    it('adds the forbidden paths of config to those that cannot be switched off', function () {
        config()->set('passorde-connect.evoliz.forbidden_paths', ['/quotes']);
        FakeEvoliz::fake();

        expect(fn () => api()->get('/quotes'))->toThrow(ForbiddenPathException::class);
        expect(fn () => api()->send('GET', '/invoices'))->toThrow(ForbiddenPathException::class);
        Http::assertNothingSent();
    });

    it('refuses every invoicing path, whatever the document it starts from', function (string $method, string $path) {
        config()->set('passorde-connect.writes_enabled', true);
        FakeEvoliz::fake();

        expect(fn () => api()->send($method, $path))->toThrow(ForbiddenPathException::class);
        Http::assertNothingSent();
    })->with([
        ['POST', '/invoices'],
        ['GET', '/invoices/9'],
        ['POST', '/invoices/9/create'],
        ['POST', '/deliveries/7/invoice'],
        ['POST', '/quotes/7/invoice'],
        ['POST', '/sale-orders/7/invoice'],
        ['POST', '/quotes/7/progress-invoice'],
        ['GET', '/credits'],
        ['GET', '/api/companies/12345/links/invoice/9'],
    ]);

    it('refuses to update, delete or send a delivery, and lets a create and a read through', function () {
        config()->set('passorde-connect.writes_enabled', true);
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/deliveries*' => Http::response([], 200)]);

        foreach ([['PATCH', '/deliveries/7'], ['PUT', '/deliveries/7'], ['DELETE', '/deliveries/7'], ['POST', '/deliveries/7/send'], ['DELETE', '/api/v1/companies/12345/deliveries/7']] as [$method, $path]) {
            expect(fn () => api()->send($method, $path))->toThrow(ForbiddenPathException::class);
        }

        Http::assertNothingSent();

        expect(api()->send('POST', '/deliveries')->status())->toBe(200)
            ->and(api()->send('GET', '/deliveries/7')->status())->toBe(200);
    });

    it('blocks every call on an expired licence, reads included, before any login', function (string $method) {
        app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-01-01']));
        Http::fake();

        expect(fn () => api()->send($method, '/clients'))->toThrow(LicenceExpiredException::class);
        Http::assertNothingSent();
    })->with(['GET', 'POST']);

    it('blocks every call without a valid licence', function () {
        app('files')->delete($this->licencePath);
        Http::fake();

        expect(fn () => api()->get('/clients'))->toThrow(LicenceInvalidException::class);
        Http::assertNothingSent();
    });

    it('lets calls through during the grace period', function () {
        app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-06-10']));
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients' => Http::response(['data' => []])]);

        expect(api()->get('/clients'))->toBe(['data' => []]);
    });

    it('checks the licence again on each page', function () {
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/articles*' => Http::response(FakeEvoliz::fixture('articles-page-1'))]);

        $pages = api()->paginate('/articles');
        $pages->current();

        app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-01-01']));

        expect(fn () => $pages->next())->toThrow(LicenceExpiredException::class);
    });
});
