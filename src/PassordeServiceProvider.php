<?php

namespace Passorde;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Passorde\Api\Contracts\RequestLog;
use Passorde\Api\DatabaseRequestLog;
use Passorde\Api\NullRequestLog;
use Passorde\Connectors\ConnectorRegistry;
use Passorde\Console\LicenceCommand;
use Passorde\Http\Controllers\LicenceController;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\DatabaseLicenceStore;
use Passorde\Licence\FileLicenceStore;
use Passorde\Licence\LicenceGuard;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceRefresher;
use Passorde\Licence\LicenceService;
use Passorde\Licence\LicenceVerifier;

/**
 * The core of Passorde Connect, shared by every connector: the licence (key, store, verifier, refresh, page and
 * command), the request log and the connector registry. Each connector module has its own provider.
 */
class PassordeServiceProvider extends ServiceProvider
{
    /** In the order they run. A new one gets the next number on the same date, so that it sorts after the others. */
    public const MIGRATIONS = [
        '2026_01_01_000000_create_passorde_requests_table.php',
        '2026_01_01_000004_create_passorde_licences_table.php',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/passorde-connect.php', 'passorde-connect');

        $this->app->singleton(ConnectorRegistry::class);

        $this->app->singleton(LicenceStore::class, fn ($app) => match ($app['config']->get('passorde-connect.licence.store')) {
            'database' => new DatabaseLicenceStore(
                $app['db'],
                $app['encrypter'],
                $app['config']->get('passorde-connect.licence.connection') ?: null,
            ),
            'file' => new FileLicenceStore((string) $app['config']->get('passorde-connect.licence.path')),
            default => throw new InvalidArgumentException('Unknown Passorde licence store: database or file.'),
        });
        // The trusted keys are those of Passorde\Licence\PublicKeys, and nothing else: no setting adds one.
        $this->app->singleton(LicenceVerifier::class, fn () => new LicenceVerifier);
        $this->app->singleton(LicenceManager::class);
        $this->app->singleton(LicenceGuard::class);
        $this->app->singleton(LicenceRefresher::class, fn ($app) => new LicenceRefresher(
            $app->make(LicenceManager::class),
            $app->make(LicenceVerifier::class),
            $app->make(LicenceStore::class),
            $this->refreshUrl(),
            $app->make(ConnectorRegistry::class),
        ));

        $this->app->singleton(RequestLog::class, fn ($app) => match ($app['config']->get('passorde-connect.request_log.driver')) {
            'database' => new DatabaseRequestLog,
            'null' => new NullRequestLog,
            default => throw new InvalidArgumentException('Unknown Passorde request log driver.'),
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'passorde');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'passorde');

        if (Passorde::enabled()) {
            $this->registerRoutes();
        }

        $this->publishes([
            __DIR__.'/../config/passorde-connect.php' => config_path('passorde-connect.php'),
        ], 'passorde-connect-config');

        $this->publishes([
            LicenceController::stylesheetPath() => public_path(LicenceController::PUBLISHED_STYLESHEET),
        ], 'passorde-assets');

        if ($this->app->runningInConsole()) {
            // publishes(), not publishesMigrations(): with update_date_on_publish, the latter renames every file and
            // so copies the already-published ones again, under new dates, each time the tag is published.
            $this->publishes(
                self::migrationsToPublish(database_path('migrations'), __DIR__.'/../database/migrations', self::MIGRATIONS),
                'passorde-migrations',
            );

            if (Passorde::enabled()) {
                $this->commands([LicenceCommand::class]);
            }
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (Passorde::enabled() && config('passorde-connect.licence.refresh_enabled')) {
                $schedule->command('passorde:licence refresh')->daily();
            }
        });
    }

    /**
     * These migrations under their own names, less those the host already has under any date: published again
     * after an update, the tag adds only the new ones. Connector modules publish theirs under the same tag.
     *
     * @param  list<string>  $files
     * @return array<string, string> Source path => destination path.
     */
    public static function migrationsToPublish(string $directory, string $source, array $files): array
    {
        $undated = fn (string $file): string => (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
        $present = array_map($undated, glob($directory.'/*.php') ?: []);
        $paths = [];

        foreach ($files as $file) {
            if (! in_array($undated($file), $present, true)) {
                $paths[$source.'/'.$file] = $directory.'/'.$file;
            }
        }

        return $paths;
    }

    /**
     * The licence page, its stylesheet and its two forms, unless passorde-connect.route.enabled switches them off.
     */
    private function registerRoutes(): void
    {
        if (config('passorde-connect.route.enabled') === false) {
            return;
        }

        Route::prefix((string) config('passorde-connect.route.prefix'))
            ->middleware((array) config('passorde-connect.route.middleware'))
            ->group(__DIR__.'/../routes/web.php');
    }

    /**
     * The configured address, or the licence service's own when the setting is empty or absent.
     */
    private function refreshUrl(): string
    {
        $url = config('passorde-connect.licence.refresh_url');

        return is_string($url) && $url !== '' ? $url : LicenceService::REFRESH_URL;
    }
}
