<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Passorde\Api\Contracts\RequestLog;
use Passorde\Api\DatabaseRequestLog;
use Passorde\Api\NullRequestLog;
use Passorde\Connectors\ConnectorRegistry;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\DatabaseLicenceStore;
use Passorde\Licence\Exceptions\LicenceInvalidException;
use Passorde\Licence\FileLicenceStore;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceReason;
use Passorde\Licence\LicenceState;
use Passorde\Licence\LicenceVerifier;
use Passorde\Licence\PublicKeys;
use Passorde\PassordeServiceProvider;
use Passorde\Tests\Support\TestLicence;

function refreshEvents(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'passorde:licence refresh'))
        ->values()
        ->all();
}

it('declares the core provider first, then each connector module, for auto-discovery', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

    expect($composer['name'])->toBe('marinailsapps/passorde-connect')
        ->and($composer['extra']['laravel']['providers'][0])->toBe(PassordeServiceProvider::class)
        ->and($composer['autoload']['psr-4'])->not->toHaveKey('Passorde\\Testing\\')
        ->and($composer['autoload-dev']['psr-4']['Passorde\\Testing\\'])->toBe('testing/');
});

it('registers the licence command, and no connector on its own', function () {
    expect(array_keys(Artisan::all()))->toContain('passorde:licence')
        ->and(app(ConnectorRegistry::class)->ids())->toBe([]);
});

it('registers no command, no route and no scheduled refresh when the host switches the component off', function () {
    $this->switchComponentOff();

    $passorde = array_values(array_filter(array_keys(Artisan::all()), fn (string $name): bool => str_starts_with($name, 'passorde:')));

    expect($passorde)->toBe([])
        ->and(Route::has('passorde.licence.show'))->toBeFalse()
        ->and(refreshEvents())->toBe([])
        ->and(app(LicenceManager::class))->toBeInstanceOf(LicenceManager::class);

    $this->get('/passorde/licence')->assertNotFound();
});

it('stays on when the host config has no enabled key', function () {
    expect(require __DIR__.'/../../config/passorde-connect.php')->toHaveKey('enabled', true)
        ->and(Passorde\Passorde::enabled())->toBeTrue();

    config()->set('passorde-connect.enabled', null);

    expect(Passorde\Passorde::enabled())->toBeTrue();
});

it('keeps the test helpers out of the autoloaded code', function () {
    expect(is_dir(__DIR__.'/../../src/Testing'))->toBeFalse()
        ->and(file_exists(__DIR__.'/../../testing/TestLicence.php'))->toBeTrue();
});

it('has no setting, environment variable or command that adds a trusted key', function (string $environment) {
    $variables = [
        'PASSORDE_PUBLIC_KEYS' => TestLicence::KID.':'.TestLicence::verifierKey(),
        'PASSORDE_LICENCE_PUBLIC_KEY' => TestLicence::verifierKey(),
        'PASSORDE_DEV' => 'true',
        'APP_DEBUG' => 'true',
    ];

    foreach ($variables as $name => $value) {
        putenv($name.'='.$value);
    }

    try {
        // The configuration as a host would load it with those variables set, plus every key a trick might try.
        config()->set('passorde-connect', require __DIR__.'/../../config/passorde-connect.php');
        foreach (['public_keys', 'trusted_keys', 'licence.public_keys', 'licence.trusted_keys', 'licence.kid', 'licence.trust'] as $key) {
            config()->set('passorde-connect.'.$key, [TestLicence::KID => TestLicence::verifierKey()]);
        }
        config()->set('app.debug', true);
        app()['env'] = $environment;

        // The tests' own verifier is dropped: what the container builds is what a host gets.
        app()->forgetInstance(LicenceVerifier::class);
        app()->forgetInstance(LicenceManager::class);
        $verifier = app(LicenceVerifier::class);

        expect((fn () => $this->publicKeys)->call($verifier))->toBe(PublicKeys::KEYS)
            ->and($verifier->verify(TestLicence::signed())->reasonCode)->toBe(LicenceReason::UnknownKid)
            ->and(fn () => app(LicenceManager::class)->install(TestLicence::signed()))->toThrow(LicenceInvalidException::class)
            ->and(app(LicenceManager::class)->status()->state)->toBe(LicenceState::Invalid)
            ->and($this->artisan('passorde:licence', ['action' => 'sign'])->run())->not->toBe(0);
    } finally {
        foreach (array_keys($variables) as $name) {
            putenv($name);
        }
        app()['env'] = 'testing';
    }
})->with(['testing', 'local', 'staging', 'production']);

it('keeps the licence key in the database by default, or in a file', function () {
    $config = require __DIR__.'/../../config/passorde-connect.php';
    expect($config['licence']['store'])->toBe('database')
        ->and($config['licence']['path'])->toEndWith('passorde/licence.key');

    config()->set('passorde-connect.licence.store', 'database');
    app()->forgetInstance(LicenceStore::class);
    expect(app(LicenceStore::class))->toBeInstanceOf(DatabaseLicenceStore::class);

    config()->set('passorde-connect.licence.store', 'file');
    app()->forgetInstance(LicenceStore::class);
    expect(app(LicenceStore::class))->toBeInstanceOf(FileLicenceStore::class);

    config()->set('passorde-connect.licence.store', 'other');
    app()->forgetInstance(LicenceStore::class);
    expect(fn () => app(LicenceStore::class))->toThrow(InvalidArgumentException::class);
});

it('publishes the configuration as config/passorde-connect.php', function () {
    $paths = ServiceProvider::pathsToPublish(PassordeServiceProvider::class, 'passorde-connect-config');

    expect(array_values($paths))->toBe([config_path('passorde-connect.php')])
        ->and(array_keys($paths)[0])->toEndWith('config/passorde-connect.php')
        ->and(ServiceProvider::pathsToPublish(PassordeServiceProvider::class, 'passorde-config'))->toBe([]);
});

it('refuses any HTTP call that a test did not fake', function () {
    Http::get('https://service.test/api/login');
})->throws(RuntimeException::class, 'without a matching fake');

it('makes the migrations publishable', function () {
    $paths = ServiceProvider::pathsToPublish(PassordeServiceProvider::class, 'passorde-migrations');

    expect($paths)->toHaveCount(2)
        ->and(count(glob(__DIR__.'/../../database/migrations/*.php')))->toBe(2);

    foreach (array_keys($paths) as $path) {
        expect($path)->toBeFile();
    }
});

it('binds the request log driver from config', function () {
    expect(app(RequestLog::class))->toBeInstanceOf(DatabaseRequestLog::class);

    config()->set('passorde-connect.request_log.driver', 'null');
    app()->forgetInstance(RequestLog::class);

    expect(app(RequestLog::class))->toBeInstanceOf(NullRequestLog::class);

    config()->set('passorde-connect.request_log.driver', 'other');
    app()->forgetInstance(RequestLog::class);

    expect(fn () => app(RequestLog::class))->toThrow(InvalidArgumentException::class);
});

it('schedules the refresh daily', function () {
    $events = refreshEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 0 * * *');
});

it('does not schedule the refresh when disabled by config', function () {
    config()->set('passorde-connect.licence.refresh_enabled', false);
    app()->forgetInstance(Schedule::class);

    expect(refreshEvents())->toBe([]);

    config()->set('passorde-connect.licence.refresh_enabled', true);
    app()->forgetInstance(Schedule::class);

    expect(refreshEvents())->toHaveCount(1);
});

it('registers the settings routes under the configured prefix', function () {
    expect(Route::getRoutes()->getByName('passorde.licence.show')->uri())->toBe('passorde/licence');
});

it('lists every PASSORDE key of the config in .env.example, with an empty value', function () {
    $config = (string) file_get_contents(__DIR__.'/../../config/passorde-connect.php');
    preg_match_all('/PASSORDE_[A-Z_]+/', $config, $keys);

    $example = array_filter(explode("\n", (string) file_get_contents(__DIR__.'/../../.env.example')));
    $expected = array_map(fn (string $key) => $key.'=', array_unique($keys[0]));

    expect($example)->toEqualCanonicalizing(array_values($expected));
});

it('defaults writes to off and the page to signed-in users', function () {
    $config = require __DIR__.'/../../config/passorde-connect.php';

    expect($config['writes_enabled'])->toBeFalse()
        ->and($config['licence']['refresh_enabled'])->toBeTrue()
        ->and($config['route']['prefix'])->toBe('passorde')
        ->and($config['route']['middleware'])->toBe(['web', 'auth']);
});

it('refuses guests on the settings page with the default middleware', function () {
    config()->set('passorde-connect.route.middleware', ['web', 'auth']);

    Route::prefix('guarded')->middleware(['web', 'auth'])->group(__DIR__.'/../../routes/web.php');

    $this->getJson('/guarded/licence')->assertUnauthorized();
    $this->postJson('/guarded/licence', ['licence' => 'x'])->assertUnauthorized();
});

it('reads every switch of the config from the environment', function (string $variable, array $key, bool $default) {
    $read = function (?string $value) use ($variable, $key): bool {
        $value === null ? putenv($variable) : putenv($variable.'='.$value);

        try {
            return data_get(require __DIR__.'/../../config/passorde-connect.php', $key);
        } finally {
            putenv($variable);
        }
    };

    expect($read(null))->toBe($default)
        ->and($read(''))->toBe($default)
        ->and($read('false'))->toBeFalse()
        ->and($read('0'))->toBeFalse()
        ->and($read('off'))->toBeFalse()
        ->and($read('true'))->toBeTrue()
        ->and($read('1'))->toBeTrue()
        ->and($read('on'))->toBeTrue();
})->with([
    'writes' => ['PASSORDE_WRITES_ENABLED', ['writes_enabled'], false],
    'schedule' => ['PASSORDE_SCHEDULE_ENABLED', ['schedule', 'enabled'], false],
    'licence refresh' => ['PASSORDE_LICENCE_REFRESH_ENABLED', ['licence', 'refresh_enabled'], true],
    'routes' => ['PASSORDE_ROUTE_ENABLED', ['route', 'enabled'], true],
    'mirror after writes' => ['PASSORDE_WRITES_MIRROR', ['writes', 'mirror'], true],
]);

it('reads every switch the config holds in this test', function () {
    preg_match_all("/'([a-z_]*enabled|mirror)' =>/", (string) file_get_contents(__DIR__.'/../../config/passorde-connect.php'), $switches);

    // The first enabled is the component's own switch, which the host sets and no environment variable reads.
    expect($switches[1])->toEqualCanonicalizing(['enabled', 'writes_enabled', 'mirror', 'enabled', 'refresh_enabled', 'enabled']);
});

it('publishes the migrations under their own names, once, however often the tag is published', function () {
    config()->set('database.migrations.update_date_on_publish', true);
    $directory = database_path('migrations');
    $ours = fn (): array => array_map('basename', glob($directory.'/*passorde*.php') ?: []);
    expect($ours())->toBe([]);

    try {
        $this->artisan('vendor:publish', ['--tag' => 'passorde-migrations'])->assertSuccessful();
        $this->artisan('vendor:publish', ['--tag' => 'passorde-migrations'])->assertSuccessful();

        expect($ours())->toBe(PassordeServiceProvider::MIGRATIONS)
            ->and(ServiceProvider::publishableMigrationPaths())->not->toContain(realpath(__DIR__.'/../../database/migrations/'.PassordeServiceProvider::MIGRATIONS[0]));
    } finally {
        foreach ($ours() as $file) {
            unlink($directory.'/'.$file);
        }
    }
});

it('leaves out the migrations a host already published under other dates', function () {
    $directory = $this->licenceDirectory.'/migrations';
    mkdir($directory, 0755, true);
    touch($directory.'/2026_11_02_101500_create_passorde_requests_table.php');
    touch($directory.'/2026_11_02_101600_create_orders_table.php');
    $source = realpath(__DIR__.'/../../database/migrations');

    expect(PassordeServiceProvider::migrationsToPublish($directory, $source, PassordeServiceProvider::MIGRATIONS))->toBe([
        $source.'/2026_01_01_000004_create_passorde_licences_table.php' => $directory.'/2026_01_01_000004_create_passorde_licences_table.php',
    ]);
});

it('lists every migration of the package', function () {
    expect(array_map('basename', glob(__DIR__.'/../../database/migrations/*.php')))->toBe(PassordeServiceProvider::MIGRATIONS);
});
