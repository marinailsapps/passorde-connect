<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Passorde\Licence\LicenceManager;
use Passorde\Sync\RunStatus;
use Passorde\Sync\SyncDeliveries;
use Passorde\Sync\Window;
use Passorde\Tests\Support\FakeEvoliz;
use Passorde\Tests\Support\FakeListings;
use Passorde\Tests\Support\TestLicence;

beforeEach(function () {
    $this->evoliz = new FakeListings;
    $this->evoliz->rows['deliveries'] = FakeListings::fixtureRows('deliveries');
    FakeListings::migrate();
    Sleep::fake();
});

function delivery(int $evolizId): object
{
    return DB::table('passorde_deliveries')->where('evoliz_id', $evolizId)->first();
}

function linesOf(int $evolizId): Collection
{
    return DB::table('passorde_delivery_lines')->where('delivery_id', delivery($evolizId)->id)->orderBy('position')->get();
}

it('defaults to the last 7 days and 31 days ahead', function () {
    app(SyncDeliveries::class)->run();

    $url = FakeEvoliz::calls()[0]->url();

    expect($url)->toContain('date_min=2026-06-08')->toContain('date_max=2026-07-16');

    expect(FakeListings::lastRun('deliveries'))
        ->window_from->toStartWith('2026-06-08')
        ->window_to->toStartWith('2026-07-16');
});

it('creates deliveries and lines, future-dated included', function () {
    [$result] = app(SyncDeliveries::class)->run();

    expect($result->status)->toBe(RunStatus::Succeeded)
        ->and($result->seen)->toBe(3)
        ->and($result->changed)->toBe(3);

    $row = delivery(8001);

    expect($row->number)->toBe('BL-8001')
        ->and($row->client_name)->toBe('Société Exemple')
        ->and((bool) $row->invoiced)->toBeFalse()
        ->and($row->comment)->toBe('Livraison matin')
        ->and((bool) delivery(8002)->invoiced)->toBeTrue()
        ->and(delivery(8003)->document_date)->toStartWith('2026-07-10');

    $lines = linesOf(8001);

    expect($lines)->toHaveCount(2)
        ->and((int) $lines[0]->article_evoliz_id)->toBe(2001)
        ->and($lines[0]->designation)->toBe('Café moulu')
        ->and($lines[1]->article_evoliz_id)->toBeNull()
        ->and((bool) $lines[1]->is_free)->toBeTrue();
});

it('leaves everything alone when nothing changed, but stamps synced_at', function () {
    app(SyncDeliveries::class)->run();
    $before = delivery(8001);
    $lineIds = linesOf(8001)->pluck('id')->all();

    $this->travel(15)->minutes();
    [$result] = app(SyncDeliveries::class)->run();

    expect($result->changed)->toBe(0)
        ->and(delivery(8001)->updated_at)->toBe($before->updated_at)
        ->and(delivery(8001)->synced_at)->not->toBe($before->synced_at)
        ->and(linesOf(8001)->pluck('id')->all())->toBe($lineIds);
});

it('updates the header alone without rewriting the lines', function () {
    app(SyncDeliveries::class)->run();
    $lineIds = linesOf(8001)->pluck('id')->all();
    $lineStamp = linesOf(8001)[0]->updated_at;

    $this->travel(15)->minutes();
    $this->evoliz->rows['deliveries'][0]['status_code'] = 20;
    [$result] = app(SyncDeliveries::class)->run();

    expect($result->changed)->toBe(1)
        ->and((bool) delivery(8001)->invoiced)->toBeTrue()
        ->and(linesOf(8001)->pluck('id')->all())->toBe($lineIds)
        ->and(linesOf(8001)[0]->updated_at)->toBe($lineStamp);
});

it('rewrites all the lines with one timestamp when their hash changes', function () {
    app(SyncDeliveries::class)->run();
    $before = delivery(8001);
    $lineIds = linesOf(8001)->pluck('id')->all();

    $this->travel(15)->minutes();
    $this->evoliz->rows['deliveries'][0]['items'][0]['quantity'] = 12;
    $this->evoliz->rows['deliveries'][0]['items'][] = ['designation' => 'Ajout', 'quantity' => 1, 'unit_price_vat_exclude' => 5, 'total' => ['vat_exclude' => 5]];
    [$result] = app(SyncDeliveries::class)->run();

    $lines = linesOf(8001);

    expect($result->changed)->toBe(1)
        ->and($lines)->toHaveCount(3)
        ->and((float) $lines[0]->quantity)->toBe(12.0)
        ->and(array_intersect($lines->pluck('id')->all(), $lineIds))->toBe([])
        ->and($lines->pluck('updated_at')->unique())->toHaveCount(1)
        ->and($lines->pluck('created_at')->unique())->toHaveCount(1)
        ->and($lines[0]->updated_at)->not->toBe($before->updated_at)
        ->and(delivery(8001)->updated_at)->not->toBe($before->updated_at);
});

it('soft-deletes deliveries of the window that Evoliz no longer returns, and restores them', function () {
    app(SyncDeliveries::class)->run();

    array_shift($this->evoliz->rows['deliveries']);
    $this->travel(15)->minutes();
    [$result] = app(SyncDeliveries::class)->run();

    expect($result->changed)->toBe(1)
        ->and(delivery(8001)->deleted_at)->not->toBeNull()
        ->and((bool) delivery(8001)->is_active)->toBeFalse()
        ->and(linesOf(8001))->toHaveCount(2)
        ->and(delivery(8002)->deleted_at)->toBeNull();

    $this->evoliz->rows['deliveries'] = FakeListings::fixtureRows('deliveries');
    $lineIds = linesOf(8001)->pluck('id')->all();
    $this->travel(15)->minutes();
    [$result] = app(SyncDeliveries::class)->run();

    expect($result->changed)->toBe(1)
        ->and(delivery(8001)->deleted_at)->toBeNull()
        ->and((bool) delivery(8001)->is_active)->toBeTrue()
        ->and(linesOf(8001)->pluck('id')->all())->toBe($lineIds);
});

it('does not touch a delivery dated outside the window', function () {
    app(SyncDeliveries::class)->run();
    $window = new Window(CarbonImmutable::parse('2026-06-14'), CarbonImmutable::parse('2026-06-20'));

    $this->evoliz->rows['deliveries'] = array_values(array_filter(
        $this->evoliz->rows['deliveries'],
        fn (array $row) => $row['deliveryid'] !== 8001,
    ));

    app(SyncDeliveries::class)->run($window);

    expect(delivery(8001)->deleted_at)->toBeNull()
        ->and(delivery(8003)->deleted_at)->toBeNull();
});

it('removes nothing when the window comes back empty', function () {
    app(SyncDeliveries::class)->run();

    $this->evoliz->rows['deliveries'] = [];
    [$result] = app(SyncDeliveries::class)->run();

    expect($result->status)->toBe(RunStatus::Succeeded)
        ->and($result->changed)->toBe(0)
        ->and(DB::table('passorde_deliveries')->whereNull('deleted_at')->count())->toBe(3);
});

it('removes nothing when the listing fails', function () {
    app(SyncDeliveries::class)->run();

    $this->evoliz->refused['deliveries'] = 500;
    [$result] = app(SyncDeliveries::class)->run();

    expect($result->failed())->toBeTrue()
        ->and(DB::table('passorde_deliveries')->whereNull('deleted_at')->count())->toBe(3);
});

it('keeps a window of 100 days in one run', function () {
    $results = app(SyncDeliveries::class)->run(new Window(CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-07-09')));

    expect($results)->toHaveCount(1);
});

it('runs a longer window as one run per calendar month', function () {
    $results = app(SyncDeliveries::class)->run(new Window(CarbonImmutable::parse('2026-04-15'), CarbonImmutable::parse('2026-08-10')));

    expect($results)->toHaveCount(5)
        ->and(DB::table('passorde_runs')->count())->toBe(5)
        ->and(DB::table('passorde_deliveries')->count())->toBe(3);

    $runs = DB::table('passorde_runs')->orderBy('id')->get();

    expect(substr($runs[0]->window_from, 0, 10))->toBe('2026-04-15')
        ->and(substr($runs[0]->window_to, 0, 10))->toBe('2026-04-30')
        ->and(substr($runs[2]->window_from, 0, 10))->toBe('2026-06-01')
        ->and((int) $runs[2]->rows_seen)->toBe(2)
        ->and((int) $runs[3]->rows_seen)->toBe(1)
        ->and(substr($runs[4]->window_to, 0, 10))->toBe('2026-08-10');
});

it('stops the monthly runs at the first failure', function () {
    $this->evoliz->refused['deliveries'] = 500;

    $results = app(SyncDeliveries::class)->run(new Window(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-06-30')));

    expect($results)->toHaveCount(1)
        ->and($results[0]->failed())->toBeTrue()
        ->and(DB::table('passorde_runs')->count())->toBe(1);
});

it('skips the whole split when another process holds the lock', function () {
    $lock = Cache::lock('passorde:sync:deliveries', 60);
    $lock->get();

    $results = app(SyncDeliveries::class)->run(new Window(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-06-30')));

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(RunStatus::Skipped)
        ->and(DB::table('passorde_runs')->count())->toBe(1)
        ->and(FakeEvoliz::calls())->toBe([]);

    $lock->release();
});

it('calls nothing and records the reason when the licence has expired', function () {
    app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-01-01']));
    Http::fake();

    [$result] = app(SyncDeliveries::class)->run();

    expect($result->failed())->toBeTrue()
        ->and(FakeListings::lastRun('deliveries')->error)->toBe($result->error);

    Http::assertNothingSent();
});

it('stores the host run id on every window part', function () {
    (require __DIR__.'/../../../database/migrations/2026_01_01_000003_add_host_run_id_to_passorde_runs_table.php')->up();

    $results = app(SyncDeliveries::class)->run(new Window(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-07-31')), 31);

    expect(count($results))->toBeGreaterThan(1)
        ->and(array_map(fn ($result) => $result->hostRunId, $results))->each->toBe(31)
        ->and(DB::table('passorde_runs')->where('kind', 'deliveries')->pluck('host_run_id')->map(fn ($id) => (int) $id)->unique()->all())->toBe([31]);
});
