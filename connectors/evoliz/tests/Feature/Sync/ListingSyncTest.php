<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Passorde\Licence\LicenceManager;
use Passorde\Records\ArticleRecord;
use Passorde\Sinks\Contracts\ArticleSink;
use Passorde\Sync\RunStatus;
use Passorde\Sync\SyncArticles;
use Passorde\Sync\SyncClients;
use Passorde\Sync\SyncPaytypes;
use Passorde\Sync\SyncUsers;
use Passorde\Tests\Support\FakeEvoliz;
use Passorde\Tests\Support\FakeListings;
use Passorde\Tests\Support\TestLicence;

beforeEach(function () {
    $this->evoliz = new FakeListings;
    FakeListings::migrate();
    Sleep::fake();
});

function article(int $evolizId): object
{
    return DB::table('passorde_articles')->where('evoliz_id', $evolizId)->first();
}

it('creates the mirror tables with the expected columns', function () {
    expect(Schema::getColumnListing('passorde_articles'))->toContain('evoliz_id', 'synced_at', 'is_active', 'created_at', 'updated_at', 'designation_raw', 'stock_qty')
        ->and(Schema::getColumnListing('passorde_deliveries'))->toContain('evoliz_id', 'synced_at', 'is_active', 'deleted_at', 'lines_hash')
        ->and(Schema::getColumnListing('passorde_runs'))->toContain('kind', 'status', 'started_at', 'finished_at', 'rows_seen', 'rows_changed', 'error');

    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_passorde_mirror_tables.php')->down();

    expect(Schema::hasTable('passorde_articles'))->toBeFalse();
});

it('creates rows and records the run', function () {
    $this->evoliz->rows['articles'] = FakeListings::fixtureRows('catalogue');

    $result = app(SyncArticles::class)->run();

    expect($result->status)->toBe(RunStatus::Succeeded)
        ->and($result->seen)->toBe(2)
        ->and($result->changed)->toBe(2);

    $row = article(2001);

    expect($row->designation)->toBe('Café moulu & torréfié Sachet 250 g')
        ->and((float) $row->unit_price_ht)->toBe(12.5)
        ->and((float) $row->stock_qty)->toBe(-3.25)
        ->and((bool) $row->is_active)->toBeTrue()
        ->and($row->synced_at)->not->toBeNull();

    $run = FakeListings::lastRun('articles');

    expect($run->status)->toBe('succeeded')
        ->and((int) $run->rows_seen)->toBe(2)
        ->and((int) $run->rows_changed)->toBe(2)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->toBeNull();
});

it('moves updated_at only on a real change, but always synced_at', function () {
    $this->evoliz->rows['articles'] = FakeListings::fixtureRows('catalogue');
    app(SyncArticles::class)->run();
    $before = article(2001);

    $this->travel(1)->hours();

    expect(app(SyncArticles::class)->run()->changed)->toBe(0);

    $after = article(2001);

    expect($after->updated_at)->toBe($before->updated_at)
        ->and($after->synced_at)->not->toBe($before->synced_at);

    $this->travel(1)->hours();
    $this->evoliz->rows['articles'][0]['stocked_quantity'] = 7;

    expect(app(SyncArticles::class)->run()->changed)->toBe(1);

    $changed = article(2001);

    expect((float) $changed->stock_qty)->toBe(7.0)
        ->and($changed->updated_at)->not->toBe($before->updated_at)
        ->and($changed->created_at)->toBe($before->created_at)
        ->and(article(2002)->updated_at)->toBe($before->updated_at);
});

it('ignores a field Passorde does not map', function () {
    $this->evoliz->rows['articles'] = FakeListings::fixtureRows('catalogue');
    app(SyncArticles::class)->run();

    $this->evoliz->rows['articles'][0]['some_new_field'] = 'x';

    expect(app(SyncArticles::class)->run()->changed)->toBe(0);
});

it('flags rows gone from the listing as inactive, never deletes them, and reactivates them', function () {
    $this->evoliz->rows['articles'] = FakeListings::fixtureRows('catalogue');
    app(SyncArticles::class)->run();

    $this->evoliz->rows['articles'] = [FakeListings::fixtureRows('catalogue')[0]];
    $this->travel(1)->hours();
    $before = article(2002);

    expect(app(SyncArticles::class)->run()->changed)->toBe(1);

    expect(DB::table('passorde_articles')->count())->toBe(2)
        ->and((bool) article(2002)->is_active)->toBeFalse()
        ->and(article(2002)->updated_at)->not->toBe($before->updated_at)
        ->and((bool) article(2001)->is_active)->toBeTrue();

    $this->evoliz->rows['articles'] = FakeListings::fixtureRows('catalogue');

    expect(app(SyncArticles::class)->run()->changed)->toBe(1)
        ->and((bool) article(2002)->is_active)->toBeTrue();
});

it('flags nothing when the listing comes back empty', function () {
    $this->evoliz->rows['articles'] = FakeListings::fixtureRows('catalogue');
    app(SyncArticles::class)->run();

    $this->evoliz->rows['articles'] = [];
    $result = app(SyncArticles::class)->run();

    expect($result->status)->toBe(RunStatus::Succeeded)
        ->and($result->seen)->toBe(0)
        ->and($result->changed)->toBe(0)
        ->and(DB::table('passorde_articles')->where('is_active', true)->count())->toBe(2);
});

it('flags nothing when the listing fails midway, and records the failure', function () {
    $this->evoliz->rows['articles'] = FakeListings::fixtureRows('catalogue');
    app(SyncArticles::class)->run();

    $this->evoliz->refused['articles'] = 500;
    $result = app(SyncArticles::class)->run();

    expect($result->status)->toBe(RunStatus::Failed)
        ->and($result->error)->toContain('HTTP 500')
        ->and(DB::table('passorde_articles')->where('is_active', true)->count())->toBe(2);

    $run = FakeListings::lastRun('articles');

    expect($run->status)->toBe('failed')
        ->and($run->error)->toContain('HTTP 500');
});

it('handles more rows than one batch across several pages', function () {
    $this->evoliz->rows['paytypes'] = array_map(fn (int $id) => ['paytypeid' => $id, 'label' => 'Type '.$id], range(1, 250));

    $result = app(SyncPaytypes::class)->run();

    expect($result->seen)->toBe(250)
        ->and($result->changed)->toBe(250)
        ->and(DB::table('passorde_paytypes')->count())->toBe(250)
        ->and(FakeListings::callsTo('paytypes'))->toBe(3);
});

it('records a second run of the same kind as skipped, without calling Evoliz', function () {
    $this->evoliz->rows['articles'] = FakeListings::fixtureRows('catalogue');
    $lock = Cache::lock('passorde:sync:articles', 60);
    $lock->get();

    $result = app(SyncArticles::class)->run();

    expect($result->status)->toBe(RunStatus::Skipped)
        ->and(FakeListings::lastRun('articles')->status)->toBe('skipped')
        ->and(FakeEvoliz::calls())->toBe([])
        ->and(DB::table('passorde_articles')->count())->toBe(0);

    $lock->release();
});

it('does not block another kind, and releases the lock', function () {
    $this->evoliz->rows['articles'] = FakeListings::fixtureRows('catalogue');
    $this->evoliz->rows['paytypes'] = FakeListings::fixtureRows('paytypes');
    $lock = Cache::lock('passorde:sync:articles', 60);
    $lock->get();

    expect(app(SyncPaytypes::class)->run()->status)->toBe(RunStatus::Succeeded);

    $lock->release();

    expect(app(SyncArticles::class)->run()->status)->toBe(RunStatus::Succeeded)
        ->and(app(SyncArticles::class)->run()->status)->toBe(RunStatus::Succeeded);
});

it('closes a run left running by a dead process', function () {
    DB::table('passorde_runs')->insert(['kind' => 'articles', 'status' => 'running', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    app(SyncArticles::class)->run();

    $old = DB::table('passorde_runs')->orderBy('id')->first();

    expect($old->status)->toBe('failed')
        ->and($old->error)->toContain('interrompue');
});

it('records the licence reason and calls nothing when the licence has expired', function () {
    app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-01-01']));
    Http::fake();

    $result = app(SyncArticles::class)->run();

    expect($result->status)->toBe(RunStatus::Failed);

    $run = FakeListings::lastRun('articles');

    expect($run->status)->toBe('failed')
        ->and($run->error)->not->toBeEmpty()
        ->and($run->error)->toBe($result->error);

    Http::assertNothingSent();
});

it('mirrors clients and their primary contact', function () {
    $this->evoliz->rows['clients'] = FakeListings::fixtureRows('clients');
    $this->evoliz->rows['contacts-clients'] = FakeListings::fixtureRows('contacts-clients');

    $result = app(SyncClients::class)->run();

    expect($result->seen)->toBe(3)
        ->and($result->changed)->toBe(5);

    $client = DB::table('passorde_clients')->where('evoliz_id', 501)->first();

    expect($client->address_line)->toBe("1 rue de l'Exemple, Bâtiment A")
        ->and($client->country_iso2)->toBe('FR');

    expect(DB::table('passorde_contacts')->pluck('evoliz_id')->map(fn ($id) => (int) $id)->all())
        ->toEqualCanonicalizing([702, 705]);

    expect(DB::table('passorde_contacts')->where('evoliz_id', 702)->value('email'))->toBe('camille.favori@exemple.org');
});

it('updates a client without touching its contact, and the other way round', function () {
    $this->evoliz->rows['clients'] = FakeListings::fixtureRows('clients');
    $this->evoliz->rows['contacts-clients'] = FakeListings::fixtureRows('contacts-clients');
    app(SyncClients::class)->run();

    $contactStamp = DB::table('passorde_contacts')->where('evoliz_id', 702)->value('updated_at');
    $this->travel(1)->hours();
    $this->evoliz->rows['clients'][0]['phone'] = '00 00 00 00 09';

    expect(app(SyncClients::class)->run()->changed)->toBe(1)
        ->and(DB::table('passorde_contacts')->where('evoliz_id', 702)->value('updated_at'))->toBe($contactStamp);

    $clientStamp = DB::table('passorde_clients')->where('evoliz_id', 501)->value('updated_at');
    $this->travel(1)->hours();
    $this->evoliz->rows['contacts-clients'][1]['lastname'] = 'Renommé';

    expect(app(SyncClients::class)->run()->changed)->toBe(1)
        ->and(DB::table('passorde_clients')->where('evoliz_id', 501)->value('updated_at'))->toBe($clientStamp)
        ->and(DB::table('passorde_contacts')->where('evoliz_id', 702)->value('full_name'))->toBe('Camille Renommé');
});

it('flags a contact that is no longer primary, or whose client is gone', function () {
    $this->evoliz->rows['clients'] = FakeListings::fixtureRows('clients');
    $this->evoliz->rows['contacts-clients'] = FakeListings::fixtureRows('contacts-clients');
    app(SyncClients::class)->run();

    // 702 and 703 lose their place; 701 becomes the primary contact of 501.
    unset($this->evoliz->rows['contacts-clients'][1], $this->evoliz->rows['contacts-clients'][2]);
    $this->evoliz->rows['contacts-clients'] = array_values($this->evoliz->rows['contacts-clients']);
    app(SyncClients::class)->run();

    expect((bool) DB::table('passorde_contacts')->where('evoliz_id', 702)->value('is_active'))->toBeFalse()
        ->and((bool) DB::table('passorde_contacts')->where('evoliz_id', 701)->value('is_active'))->toBeTrue();

    // 502 leaves the listing, with its contact.
    unset($this->evoliz->rows['clients'][1]);
    $this->evoliz->rows['clients'] = array_values($this->evoliz->rows['clients']);
    app(SyncClients::class)->run();

    expect((bool) DB::table('passorde_clients')->where('evoliz_id', 502)->value('is_active'))->toBeFalse()
        ->and((bool) DB::table('passorde_contacts')->where('evoliz_id', 705)->value('is_active'))->toBeFalse();
});

it('fails the clients run when the contacts cannot be read, before touching any client', function () {
    $this->evoliz->rows['clients'] = FakeListings::fixtureRows('clients');
    $this->evoliz->refused['contacts-clients'] = 500;

    expect(app(SyncClients::class)->run()->status)->toBe(RunStatus::Failed)
        ->and(DB::table('passorde_clients')->count())->toBe(0);
});

it('succeeds with no row when the users scope is refused, and flags nobody', function () {
    $this->evoliz->rows['users'] = FakeListings::fixtureRows('users');
    app(SyncUsers::class)->run();

    $this->evoliz->refused['users'] = 403;
    $result = app(SyncUsers::class)->run();

    expect($result->status)->toBe(RunStatus::Succeeded)
        ->and($result->seen)->toBe(0)
        ->and((bool) DB::table('passorde_users')->where('evoliz_id', 91)->value('is_active'))->toBeTrue();
});

it('hands the records to a sink bound by the host, and leaves the mirror empty', function () {
    $sink = new class implements ArticleSink
    {
        /** @var list<ArticleRecord> */
        public array $received = [];

        public ?array $seen = null;

        public function upsert(array $records, CarbonImmutable $syncedAt): int
        {
            array_push($this->received, ...$records);

            return count($records);
        }

        public function deactivateMissing(array $seenIds, CarbonImmutable $syncedAt): int
        {
            $this->seen = $seenIds;

            return 0;
        }
    };
    app()->instance(ArticleSink::class, $sink);
    $this->evoliz->rows['articles'] = FakeListings::fixtureRows('catalogue');

    $result = app(SyncArticles::class)->run();

    expect($result->changed)->toBe(2)
        ->and($sink->received[0])->toBeInstanceOf(ArticleRecord::class)
        ->and($sink->seen)->toBe([2001, 2002])
        ->and(DB::table('passorde_articles')->count())->toBe(0);
});

function migrateHostRunId(): object
{
    $migration = require __DIR__.'/../../../database/migrations/2026_01_01_000003_add_host_run_id_to_passorde_runs_table.php';
    $migration->up();

    return $migration;
}

it('stores the host run id on the run and its result', function () {
    migrateHostRunId();
    $this->evoliz->rows['articles'] = FakeListings::fixtureRows('catalogue');

    $result = app(SyncArticles::class)->run(hostRunId: 77);

    expect($result->hostRunId)->toBe(77)
        ->and((int) FakeListings::lastRun('articles')->host_run_id)->toBe(77);

    expect(app(SyncClients::class)->run()->hostRunId)->toBeNull()
        ->and(FakeListings::lastRun('clients')->host_run_id)->toBeNull();
});

it('stores the host run id on a skipped run', function () {
    migrateHostRunId();
    $lock = Cache::lock('passorde:sync:articles', 60);
    $lock->get();

    $result = app(SyncArticles::class)->run(5);
    $lock->release();

    expect($result->status)->toBe(RunStatus::Skipped)
        ->and($result->hostRunId)->toBe(5)
        ->and((int) FakeListings::lastRun('articles')->host_run_id)->toBe(5);
});

it('needs no migration from a host that never gives a run id', function () {
    expect(Schema::hasColumn('passorde_runs', 'host_run_id'))->toBeFalse()
        ->and(app(SyncPaytypes::class)->run()->status)->toBe(RunStatus::Succeeded);
});

it('adds and drops the host run id column', function () {
    $migration = migrateHostRunId();

    expect(Schema::hasColumn('passorde_runs', 'host_run_id'))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('passorde_runs', 'host_run_id'))->toBeFalse();
});
