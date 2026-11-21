<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Passorde\Licence\LicenceManager;
use Passorde\Tests\Support\TestLicence;

function writeLicenceFile(string $directory, string $raw): string
{
    app('files')->ensureDirectoryExists($directory);
    $path = $directory.'/incoming.json';
    file_put_contents($path, $raw);

    return $path;
}

it('shows the licence without the token or the e-mail address', function () {
    app(LicenceManager::class)->install(TestLicence::signed());

    $this->artisan('passorde:licence', ['action' => 'show'])
        ->expectsOutputToContain('Valide')
        ->expectsOutputToContain('Exemple SARL')
        ->expectsOutputToContain('lic_test')
        ->doesntExpectOutputToContain('token-test')
        ->doesntExpectOutputToContain('contact@exemple.org')
        ->assertExitCode(0);
});

it('shows that nothing is installed', function () {
    $this->artisan('passorde:licence', ['action' => 'show'])
        ->expectsOutputToContain('Aucune licence')
        ->assertExitCode(0);
});

it('installs a licence from a path', function () {
    $raw = TestLicence::signed();
    $path = writeLicenceFile($this->licenceDirectory, $raw);

    $this->artisan('passorde:licence', ['action' => 'install', 'path' => $path])
        ->expectsOutputToContain('Licence installée.')
        ->assertExitCode(0);

    expect(file_get_contents($this->licencePath))->toBe($raw);
});

it('refuses an invalid licence, gives the reason and keeps the current one', function () {
    $good = TestLicence::signed();
    app(LicenceManager::class)->install($good);
    $path = writeLicenceFile($this->licenceDirectory, TestLicence::signed(['product' => 'autre']));

    $this->artisan('passorde:licence', ['action' => 'install', 'path' => $path])
        ->expectsOutputToContain('autre produit')
        ->assertExitCode(1);

    expect(file_get_contents($this->licencePath))->toBe($good);
});

it('fails on a missing path', function () {
    $this->artisan('passorde:licence', ['action' => 'install', 'path' => $this->licenceDirectory.'/absent.key'])->assertExitCode(1);
});

it('asks for the key, hidden, when no path is given', function () {
    $key = TestLicence::signed();

    $this->artisan('passorde:licence', ['action' => 'install'])
        ->expectsQuestion('Clé de licence (pck1.…)', '  '.$key."\n")
        ->expectsOutputToContain('Licence installée.')
        ->assertExitCode(0);

    expect(file_get_contents($this->licencePath))->toBe($key);
});

it('refuses an empty answer', function () {
    $this->artisan('passorde:licence', ['action' => 'install'])
        ->expectsQuestion('Clé de licence (pck1.…)', '')
        ->expectsOutputToContain('Collez une clé de licence')
        ->assertExitCode(1);
});

it('shows the connectors the licence covers', function () {
    app(LicenceManager::class)->install(TestLicence::signed(['connectors' => ['acme', 'other']]));

    $this->artisan('passorde:licence', ['action' => 'show'])
        ->expectsOutputToContain('acme, other')
        ->assertExitCode(0);
});

it('has no action that signs a licence', function () {
    $this->artisan('passorde:licence', ['action' => 'sign'])
        ->expectsOutputToContain('Actions possibles : show, install, refresh.')
        ->assertExitCode(1);
});

it('refreshes the licence', function () {
    app(LicenceManager::class)->install(TestLicence::signed());
    Http::fake(['*' => Http::response(TestLicence::signed(['expires_at' => '2027-12-31']))]);

    $this->artisan('passorde:licence', ['action' => 'refresh'])
        ->expectsOutputToContain('Licence rafraîchie.')
        ->assertExitCode(0);
});

it('reports a failed refresh', function () {
    app(LicenceManager::class)->install(TestLicence::signed());
    Http::fake(['*' => Http::response('', 500)]);

    $this->artisan('passorde:licence', ['action' => 'refresh'])
        ->expectsOutputToContain('500')
        ->assertExitCode(1);
});

it('exits quietly when there is nothing to refresh', function (?Closure $install, string $output) {
    if ($install !== null) {
        $install();
    }
    Http::fake();
    Log::spy();

    $this->artisan('passorde:licence', ['action' => 'refresh'])
        ->expectsOutputToContain($output)
        ->doesntExpectOutputToContain('échoué')
        ->assertExitCode(0);

    Http::assertNothingSent();
    Log::shouldNotHaveReceived('warning');
})->with([
    'no licence installed' => [null, "Rien à rafraîchir : aucune licence n'est installée."],
    'lifetime licence' => [
        fn () => app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => null, 'licence_token' => null])),
        'Rien à rafraîchir : licence à vie sans jeton de rafraîchissement.',
    ],
    'no token' => [
        fn () => app(LicenceManager::class)->install(TestLicence::signed(without: ['licence_token'])),
        'Rien à rafraîchir : la licence ne contient pas de jeton de rafraîchissement.',
    ],
]);

it('rejects an unknown action', function () {
    $this->artisan('passorde:licence', ['action' => 'nope'])->assertExitCode(1);
});
