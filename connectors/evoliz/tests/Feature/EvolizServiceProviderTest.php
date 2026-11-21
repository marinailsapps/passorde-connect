<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\EvolizClient;
use Passorde\Api\ForbiddenPaths;
use Passorde\Connectors\ConnectorRegistry;
use Passorde\Evoliz\EvolizConnector;
use Passorde\Evoliz\EvolizServiceProvider;
use Passorde\Licence\Exceptions\LicenceInvalidException;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceReason;
use Passorde\Licence\LicenceState;
use Passorde\PassordeServiceProvider;
use Passorde\Tests\Support\FakeEvoliz;
use Passorde\Tests\Support\TestLicence;

it('is declared for auto-discovery after the core provider', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../../../composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toBe([PassordeServiceProvider::class, EvolizServiceProvider::class]);
});

it('registers itself as the connector evoliz, the default one', function () {
    $connectors = app(ConnectorRegistry::class);

    expect($connectors->ids())->toBe(['evoliz'])
        ->and($connectors->default())->toBe(EvolizConnector::ID)
        ->and($connectors->name('evoliz'))->toBe('Passorde Connect — Evoliz')
        ->and($connectors->notice('evoliz'))->toBe('Passorde Connect — Evoliz : compatible avec Evoliz, non affilié à Evoliz SAS / Visma.')
        ->and($connectors->notices())->toBe([EvolizConnector::NOTICE]);
});

it('registers its commands', function () {
    expect(array_keys(Artisan::all()))->toContain('passorde:licence', 'passorde:ping', 'passorde:status', 'passorde:sync');
});

it('registers no command and schedules nothing when the host switches the component off', function () {
    $this->switchComponentOff();
    // Read when the schedule is first resolved, below: switched on, it would add the sync runs.
    config()->set('passorde-connect.schedule.enabled', true);

    $commands = array_keys(Artisan::all());
    $passorde = array_values(array_filter($commands, fn (string $name): bool => str_starts_with($name, 'passorde:')));
    $events = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode("\n");

    expect($passorde)->toBe([])
        ->and($events)->not->toContain('passorde:')
        ->and(app(EvolizApi::class))->toBeInstanceOf(EvolizClient::class)
        ->and(app(ConnectorRegistry::class)->ids())->toBe([EvolizConnector::ID]);
});

it('binds the transport contract', function () {
    expect(app(EvolizApi::class))->toBeInstanceOf(EvolizClient::class)
        ->and(app(EvolizApi::class))->toBe(app(EvolizApi::class));
});

it('defaults to forbidding invoices, with a 30 s timeout', function () {
    $config = require __DIR__.'/../../config/evoliz.php';
    $core = require __DIR__.'/../../../../config/passorde-connect.php';

    expect($config['forbidden_paths'])->toBe(ForbiddenPaths::ALWAYS)
        ->and($config['timeout'])->toBe(30)
        ->and($core['writes']['mirror'])->toBeTrue()
        ->and($core['cache_store'])->toBeNull();
});

it('keeps its settings in its own folder, read by the host under passorde-connect.evoliz', function () {
    $core = (string) file_get_contents(__DIR__.'/../../../../config/passorde-connect.php');

    expect(stripos($core, 'evoliz'))->toBeFalse()
        ->and(config('passorde-connect.evoliz.timeout'))->toBe(30)
        ->and(config('passorde-connect.evoliz.forbidden_paths'))->toBe(ForbiddenPaths::ALWAYS)
        ->and(config('passorde-connect.evoliz.rate_limit.pause_seconds'))->toBe(60)
        ->and(config('passorde-connect.licence.store'))->toBe('file');
});

it('lets the host config/passorde-connect.php override its settings key by key', function () {
    $app = $this->createApplication();
    $app['config']->set('passorde-connect', ['evoliz' => ['timeout' => 5, 'company_id' => 42]]);
    (new EvolizServiceProvider($app))->register();

    expect($app['config']->get('passorde-connect.evoliz.timeout'))->toBe(5)
        ->and($app['config']->get('passorde-connect.evoliz.company_id'))->toBe(42)
        ->and($app['config']->get('passorde-connect.evoliz.connect_timeout'))->toBe(10);
});

it('lists every PASSORDE_EVOLIZ key of its config in its own .env.example, and the core lists none', function () {
    preg_match_all("/env\('(PASSORDE_[A-Z_]+)'/", (string) file_get_contents(__DIR__.'/../../config/evoliz.php'), $keys);
    $example = array_filter(explode("\n", (string) file_get_contents(__DIR__.'/../../.env.example')));
    $core = (string) file_get_contents(__DIR__.'/../../../../.env.example');

    expect($example)->toEqualCanonicalizing(array_map(fn (string $key) => $key.'=', array_values(array_unique($keys[1]))))
        ->and($core)->not->toContain('EVOLIZ');
});

it('publishes its migrations under the same tag as the core', function () {
    $paths = ServiceProvider::pathsToPublish(EvolizServiceProvider::class, 'passorde-migrations');

    expect(array_map('basename', array_keys($paths)))->toBe(EvolizServiceProvider::MIGRATIONS)
        ->and(array_map('basename', glob(__DIR__.'/../../database/migrations/*.php')))->toBe(EvolizServiceProvider::MIGRATIONS);

    foreach (array_keys($paths) as $path) {
        expect($path)->toBeFile();
    }
});

it('refuses to call Evoliz with a licence that does not list evoliz', function () {
    FakeEvoliz::configure();
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients*' => Http::response(['data' => []])]);
    app(LicenceManager::class)->install(TestLicence::signed());

    // Written to the store directly: install() refuses such a licence for the default connector.
    file_put_contents($this->licencePath, TestLicence::signed(['connectors' => ['other']]));

    $status = app(LicenceManager::class)->status();
    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::ConnectorNotCovered)
        ->and($status->reason())->toBe('Cette licence ne couvre pas le connecteur Passorde Connect — Evoliz (connecteurs couverts : other). '
            .'Passorde Connect — Evoliz : compatible avec Evoliz, non affilié à Evoliz SAS / Visma.')
        ->and(fn () => app(EvolizApi::class)->get('/clients'))->toThrow(LicenceInvalidException::class, 'ne couvre pas le connecteur Passorde Connect — Evoliz');

    Http::assertNothingSent();
});

it('refuses to install a licence that does not list evoliz', function () {
    expect(fn () => app(LicenceManager::class)->install(TestLicence::signed(['connectors' => ['other']])))
        ->toThrow(LicenceInvalidException::class, 'non affilié à Evoliz SAS / Visma');
});

it('accepts a licence that lists evoliz among others', function () {
    app(LicenceManager::class)->install(TestLicence::signed(['connectors' => ['other', 'evoliz']]));

    expect(app(LicenceManager::class)->status()->state)->toBe(LicenceState::Valid)
        ->and(app(LicenceManager::class)->status('other')->state)->toBe(LicenceState::Valid)
        ->and(app(LicenceManager::class)->status('third')->reasonCode)->toBe(LicenceReason::ConnectorNotCovered);
});

it('names the connector by its product name in passorde:licence show, with the notice', function () {
    app(LicenceManager::class)->install(TestLicence::signed());

    expect(Artisan::call('passorde:licence', ['action' => 'show']))->toBe(0)
        ->and(Artisan::output())->toMatch('/Connecteurs couverts\s*\|\s*Passorde Connect — Evoliz\s*\|/u')
        ->toContain("\nPassorde Connect — Evoliz : compatible avec Evoliz, non affilié à Evoliz SAS / Visma.\n");
});
