<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Licence\LicenceManager;
use Passorde\Tests\Support\FakeEvoliz;
use Passorde\Tests\Support\TestLicence;

beforeEach(function () {
    FakeEvoliz::configure();
    Sleep::fake();
});

it('logs in, makes one cheap read and prints the state', function () {
    FakeEvoliz::fake([
        FakeEvoliz::SCOPE.'/vat-rates*' => Http::response(FakeEvoliz::fixture('vat-rates'), 200, ['X-RateLimit-Limit' => '100', 'X-RateLimit-Remaining' => '97']),
    ]);

    $this->artisan('passorde:ping')
        ->expectsOutputToContain('Société : 12345')
        ->expectsOutputToContain('Appels restants : 97')
        ->expectsOutputToContain('Âge du jeton : 0 s')
        ->doesntExpectOutputToContain(FakeEvoliz::TOKEN)
        ->doesntExpectOutputToContain(FakeEvoliz::SECRET)
        ->assertExitCode(0);

    expect(FakeEvoliz::logins())->toBe(1)
        ->and(array_map(fn (Request $request) => $request->url(), FakeEvoliz::calls()))
        ->toBe([FakeEvoliz::BASE.'/api/v1/companies/12345/vat-rates?per_page=1']);
});

it('works with writes disabled, since it only reads', function () {
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/vat-rates*' => Http::response(FakeEvoliz::fixture('vat-rates'))]);

    $this->artisan('passorde:ping')->expectsOutputToContain('Appels restants : inconnu')->assertExitCode(0);
});

it('reports the age of a token already cached', function () {
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/vat-rates*' => Http::response(FakeEvoliz::fixture('vat-rates'))]);

    $this->artisan('passorde:ping')->assertExitCode(0);
    $this->travel(90)->seconds();

    $this->artisan('passorde:ping')->expectsOutputToContain('Âge du jeton : 90 s')->assertExitCode(0);

    expect(FakeEvoliz::logins())->toBe(1);
});

it('refuses when the licence blocks, without calling Evoliz', function () {
    app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-01-01']));
    Http::fake();

    $this->artisan('passorde:ping')
        ->expectsOutputToContain('Refusé par la licence')
        ->assertExitCode(2);

    Http::assertNothingSent();
});

it('ends with one licence sentence and exit code 2 when no key is installed, before touching the transport', function () {
    // As in Passorde standalone: the switch is on, no key, and the host's transport throws on any call.
    File::delete(config('passorde-connect.licence.path'));
    app()->instance(EvolizApi::class, new class implements EvolizApi
    {
        public function get(string $path, array $query = []): array
        {
            throw new LogicException("GET {$path} called in file mode.");
        }

        public function paginate(string $path, array $query = [], int $perPage = 100): Generator
        {
            throw new LogicException("GET {$path} called in file mode.");
        }

        public function send(string $method, string $path, array $options = []): Response
        {
            throw new LogicException("{$method} {$path} called in file mode.");
        }
    });
    Http::fake();

    $this->artisan('passorde:ping')
        ->expectsOutput("Refusé par la licence : Aucune licence n'est installée.")
        ->doesntExpectOutputToContain('file mode')
        ->assertExitCode(2);

    Http::assertNothingSent();
});

it('fails on a refused login', function () {
    Http::fake([FakeEvoliz::LOGIN => Http::response(FakeEvoliz::fixture('error-message'), 401)]);

    $this->artisan('passorde:ping')
        ->expectsOutputToContain('HTTP 401 — Unauthenticated.')
        ->assertExitCode(1);
});

it('fails on an API error', function () {
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/vat-rates*' => Http::response(FakeEvoliz::fixture('error-error'), 404)]);

    $this->artisan('passorde:ping')->expectsOutputToContain('HTTP 404 — Company not found')->assertExitCode(1);
});

it('fails when Evoliz cannot be reached', function () {
    Http::fake([FakeEvoliz::LOGIN => fn () => throw new ConnectionException('cURL error 7')]);

    $this->artisan('passorde:ping')->expectsOutputToContain('cURL error 7')->assertExitCode(1);
});

it('fails on an incomplete configuration, naming the setting', function () {
    config()->set('passorde-connect.evoliz.public_key', null);
    Http::fake();

    $this->artisan('passorde:ping')->expectsOutputToContain('PASSORDE_EVOLIZ_PUBLIC_KEY')->assertExitCode(1);

    Http::assertNothingSent();
});
