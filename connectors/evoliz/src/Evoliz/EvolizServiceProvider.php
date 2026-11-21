<?php

namespace Passorde\Evoliz;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\EvolizClient;
use Passorde\Api\EvolizToken;
use Passorde\Api\RateLimitMonitor;
use Passorde\Connectors\ConnectorRegistry;
use Passorde\Console\PingCommand;
use Passorde\Console\StatusCommand;
use Passorde\Console\SyncCommand;
use Passorde\Passorde;
use Passorde\PassordeServiceProvider;
use Passorde\Sinks\Contracts\ArticleSink;
use Passorde\Sinks\Contracts\ClientSink;
use Passorde\Sinks\Contracts\DeliverySink;
use Passorde\Sinks\Contracts\PaytypeSink;
use Passorde\Sinks\Contracts\UserSink;
use Passorde\Sinks\Mirror\MirrorArticleSink;
use Passorde\Sinks\Mirror\MirrorClientSink;
use Passorde\Sinks\Mirror\MirrorDeliverySink;
use Passorde\Sinks\Mirror\MirrorPaytypeSink;
use Passorde\Sinks\Mirror\MirrorUserSink;
use Passorde\Sync\SyncSchedule;

/**
 * The Evoliz connector of Passorde Connect: transport, reads, writes, sync, mirror tables and their commands. Its
 * settings are connectors/evoliz/config/evoliz.php, merged under passorde-connect.evoliz. Needs the core provider, registered before it.
 */
class EvolizServiceProvider extends ServiceProvider
{
    /** In the order they run, numbered with the core's (PassordeServiceProvider::MIGRATIONS). */
    public const MIGRATIONS = [
        '2026_01_01_000001_create_passorde_runs_table.php',
        '2026_01_01_000002_create_passorde_mirror_tables.php',
        '2026_01_01_000003_add_host_run_id_to_passorde_runs_table.php',
    ];

    public function register(): void
    {
        // Under passorde-connect.evoliz: the keys a host reads stay those of the single config file of earlier
        // versions. An evoliz array in the host's config/passorde-connect.php wins key by key.
        $this->mergeConfigFrom(__DIR__.'/../../config/evoliz.php', 'passorde-connect.evoliz');

        $this->app->singleton(RateLimitMonitor::class);
        $this->app->singleton(EvolizToken::class);
        $this->app->singleton(EvolizApi::class, EvolizClient::class);

        // The mirror is the default: a host may bind its own implementation of any of these.
        $this->app->bindIf(ArticleSink::class, MirrorArticleSink::class);
        $this->app->bindIf(ClientSink::class, MirrorClientSink::class);
        $this->app->bindIf(PaytypeSink::class, MirrorPaytypeSink::class);
        $this->app->bindIf(UserSink::class, MirrorUserSink::class);
        $this->app->bindIf(DeliverySink::class, MirrorDeliverySink::class);

        $this->callAfterResolving(ConnectorRegistry::class, function (ConnectorRegistry $connectors): void {
            $connectors->register(EvolizConnector::ID, EvolizConnector::NAME, EvolizConnector::NOTICE);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                PassordeServiceProvider::migrationsToPublish(database_path('migrations'), __DIR__.'/../../database/migrations', self::MIGRATIONS),
                'passorde-migrations',
            );

            if (Passorde::enabled()) {
                $this->commands([PingCommand::class, StatusCommand::class, SyncCommand::class]);
            }
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (Passorde::enabled() && config('passorde-connect.schedule.enabled')) {
                SyncSchedule::register($schedule);
            }
        });
    }
}
