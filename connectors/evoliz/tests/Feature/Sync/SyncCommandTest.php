<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Passorde\Licence\LicenceManager;
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
use Passorde\Tests\Support\FakeEvoliz;
use Passorde\Tests\Support\FakeListings;
use Passorde\Tests\Support\TestLicence;

beforeEach(function () {
    $this->evoliz = new FakeListings;
    FakeListings::migrate();
    Sleep::fake();

    $this->evoliz->rows = [
        'articles' => FakeListings::fixtureRows('catalogue'),
        'clients' => FakeListings::fixtureRows('clients'),
        'contacts-clients' => FakeListings::fixtureRows('contacts-clients'),
        'paytypes' => FakeListings::fixtureRows('paytypes'),
        'users' => FakeListings::fixtureRows('users'),
        'deliveries' => FakeListings::fixtureRows('deliveries'),
    ];
});

function syncEvents(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'passorde:sync'))
        ->values()
        ->all();
}

function syncEvent(string $command, ?string $expression = null): Event
{
    return collect(syncEvents())->first(fn (Event $event) => str_ends_with((string) $event->command, $command)
        && ($expression === null || $event->expression === $expression));
}

it('prints one line with the counts and exits 0', function () {
    $this->artisan('passorde:sync', ['kind' => 'articles'])
        ->expectsOutput('articles : réussie — vues 2, modifiées 2')
        ->assertExitCode(0);
});

it('runs every kind with all', function () {
    $this->artisan('passorde:sync', ['kind' => 'all'])
        ->expectsOutputToContain('paytypes : réussie')
        ->expectsOutputToContain('clients : réussie')
        ->expectsOutputToContain('articles : réussie')
        ->expectsOutputToContain('users : réussie')
        ->expectsOutputToContain('deliveries [2026-06-08 → 2026-07-16] : réussie')
        ->assertExitCode(0);

    expect(DB::table('passorde_runs')->count())->toBe(5);
});

it('refuses an unknown kind', function () {
    $this->artisan('passorde:sync', ['kind' => 'invoices'])->expectsOutputToContain('Type inconnu')->assertExitCode(1);

    expect(FakeEvoliz::calls())->toBe([]);
});

it('exits non-zero on a failed run, with the error, and carries on with the other kinds', function () {
    $this->evoliz->refused['articles'] = 500;

    $this->artisan('passorde:sync', ['kind' => 'all'])
        ->expectsOutputToContain('articles : échouée — vues 0, modifiées 0 — HTTP 500')
        ->expectsOutputToContain('deliveries [2026-06-08 → 2026-07-16] : réussie')
        ->assertExitCode(1);
});

it('exits 0 on a skipped run', function () {
    $lock = Cache::lock('passorde:sync:articles', 60);
    $lock->get();

    $this->artisan('passorde:sync', ['kind' => 'articles'])->expectsOutputToContain('articles : ignorée')->assertExitCode(0);

    $lock->release();
});

it('exits non-zero and prints the reason when the licence blocks', function () {
    app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-01-01']));
    Http::fake();

    $this->artisan('passorde:sync', ['kind' => 'articles'])->expectsOutputToContain('articles : échouée')->assertExitCode(1);

    Http::assertNothingSent();
});

it('takes --since and --until', function () {
    $this->artisan('passorde:sync', ['kind' => 'deliveries', '--since' => '2026-06-01', '--until' => '2026-06-13'])
        ->expectsOutput('deliveries [2026-06-01 → 2026-06-13] : réussie — vues 1, modifiées 1')
        ->assertExitCode(0);
});

it('takes --days and keeps the days ahead', function () {
    $this->artisan('passorde:sync', ['kind' => 'deliveries', '--days' => '60'])
        ->expectsOutputToContain('deliveries [2026-04-16 → 2026-07-16] : réussie')
        ->assertExitCode(0);
});

it('takes --since alone, up to the default end', function () {
    $this->artisan('passorde:sync', ['kind' => 'deliveries', '--since' => '2026-06-10'])
        ->expectsOutputToContain('deliveries [2026-06-10 → 2026-07-16]')
        ->assertExitCode(0);
});

it('takes --until alone, 7 days back', function () {
    $this->artisan('passorde:sync', ['kind' => 'deliveries', '--until' => '2026-06-20'])
        ->expectsOutputToContain('deliveries [2026-06-13 → 2026-06-20]')
        ->assertExitCode(0);
});

it('prints one line per month on a long window', function () {
    $this->artisan('passorde:sync', ['kind' => 'deliveries', '--since' => '2026-01-10', '--until' => '2026-05-05'])
        ->expectsOutputToContain('deliveries [2026-01-10 → 2026-01-31]')
        ->expectsOutputToContain('deliveries [2026-05-01 → 2026-05-05]')
        ->assertExitCode(0);
});

it('refuses bad window options without calling Evoliz', function (array $options, string $message) {
    $this->artisan('passorde:sync', array_merge(['kind' => 'deliveries'], $options))
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect(FakeEvoliz::calls())->toBe([]);
})->with([
    'bad since' => [['--since' => 'hier'], '--since'],
    'impossible date' => [['--until' => '2026-02-31'], '--until'],
    'bad days' => [['--days' => 'x'], '--days'],
    'negative days' => [['--days' => '-3'], '--days'],
    'days and since' => [['--days' => '3', '--since' => '2026-06-01'], 'ensemble'],
    'inverted' => [['--since' => '2026-06-10', '--until' => '2026-06-01'], 'inversée'],
]);

it('warns that the window options concern deliveries only', function () {
    $this->artisan('passorde:sync', ['kind' => 'paytypes', '--days' => '3'])
        ->expectsOutputToContain('ne concernent que deliveries')
        ->assertExitCode(0);
});

it('exits non-zero with the reason when the runs table is missing', function () {
    (require __DIR__.'/../../../database/migrations/2026_01_01_000001_create_passorde_runs_table.php')->down();

    $this->artisan('passorde:sync', ['kind' => 'paytypes'])->expectsOutputToContain('paytypes :')->assertExitCode(1);
});

it('shows the last run per kind before the licence', function () {
    $this->evoliz->refused['articles'] = 500;
    $this->artisan('passorde:sync', ['kind' => 'paytypes'])->assertExitCode(0);
    $this->artisan('passorde:sync', ['kind' => 'articles'])->assertExitCode(1);

    $this->artisan('passorde:status')
        ->expectsOutput('paytypes : 2026-06-15 10:00 — réussie — vues 2, modifiées 2')
        ->expectsOutputToContain('articles : 2026-06-15 10:00 — échouée — vues 0, modifiées 0 — HTTP 500')
        ->expectsOutput('clients : jamais exécutée')
        ->expectsOutputToContain('Valide')
        ->assertExitCode(0);
});

it('prints the runs before the licence line', function () {
    Artisan::call('passorde:status');
    $output = Artisan::output();

    expect(strpos($output, 'deliveries :'))->toBeLessThan(strpos($output, 'Valide'));
});

it('still answers about the licence before the migrations', function () {
    (require __DIR__.'/../../../database/migrations/2026_01_01_000001_create_passorde_runs_table.php')->down();

    $this->artisan('passorde:status')
        ->expectsOutputToContain('indisponibles')
        ->expectsOutputToContain('Valide')
        ->assertExitCode(0);
});

it('schedules nothing by default', function () {
    expect(config('passorde-connect.schedule.enabled'))->toBeFalse()
        ->and(syncEvents())->toBe([]);
});

it('registers the schedule behind the config, without overlapping', function () {
    config()->set('passorde-connect.schedule.enabled', true);
    app()->forgetInstance(Schedule::class);

    $events = syncEvents();

    expect($events)->toHaveCount(6);

    foreach ($events as $event) {
        expect($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe(30);
    }

    expect(syncEvent('passorde:sync articles', '*/15 8-18 * * 1-5'))->not->toBeNull()
        ->and(syncEvent('passorde:sync articles', '0 * * * *'))->not->toBeNull()
        ->and(syncEvent('passorde:sync deliveries --days=7')->expression)->toBe('*/15 * * * *')
        ->and(syncEvent('passorde:sync clients')->expression)->toBe('7 2 * * *')
        ->and(syncEvent('passorde:sync paytypes')->expression)->toBe('22 2 * * *')
        ->and(syncEvent('passorde:sync deliveries --days=60')->expression)->toBe('37 2 * * *');
});

it('applies the hourly articles run outside working hours only', function () {
    config()->set('passorde-connect.schedule.enabled', true);
    config()->set('passorde-connect.schedule.timezone', 'Europe/Paris');
    app()->forgetInstance(Schedule::class);

    $hourly = syncEvent('passorde:sync articles', '0 * * * *');

    expect($hourly->timezone)->toBe('Europe/Paris');

    // A Monday noon, then the same Monday at 22:00, then a Saturday noon.
    $this->travelTo('2026-06-15 12:00:00 Europe/Paris');
    expect($hourly->filtersPass(app()))->toBeFalse();

    $this->travelTo('2026-06-15 22:00:00 Europe/Paris');
    expect($hourly->filtersPass(app()))->toBeTrue();

    $this->travelTo('2026-06-20 12:00:00 Europe/Paris');
    expect($hourly->filtersPass(app()))->toBeTrue();
});

it('binds the mirror sinks by default', function () {
    expect(app(ArticleSink::class))->toBeInstanceOf(MirrorArticleSink::class)
        ->and(app(ClientSink::class))->toBeInstanceOf(MirrorClientSink::class)
        ->and(app(PaytypeSink::class))->toBeInstanceOf(MirrorPaytypeSink::class)
        ->and(app(UserSink::class))->toBeInstanceOf(MirrorUserSink::class)
        ->and(app(DeliverySink::class))->toBeInstanceOf(MirrorDeliverySink::class);
});
