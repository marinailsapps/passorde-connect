<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\DatabaseLicenceStore;
use Passorde\Licence\Exceptions\LicenceStoreException;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceReason;
use Passorde\Licence\LicenceRefresher;
use Passorde\Licence\LicenceState;
use Passorde\Tests\Support\TestLicence;

function licenceTable(): Migration
{
    return require __DIR__.'/../../database/migrations/2026_01_01_000004_create_passorde_licences_table.php';
}

function useDatabaseStore(): LicenceStore
{
    config()->set('passorde-connect.licence.store', 'database');
    app()->forgetInstance(LicenceStore::class);
    app()->forgetInstance(LicenceManager::class);
    app()->forgetInstance(LicenceRefresher::class);

    return app(LicenceStore::class);
}

beforeEach(function () {
    licenceTable()->up();
});

it('keeps the key encrypted with the application key, never in clear', function () {
    $store = useDatabaseStore();
    $key = TestLicence::signed();

    app(LicenceManager::class)->install($key);

    $payload = DB::table('passorde_licences')->where('slot', 'current')->value('payload');

    expect($store)->toBeInstanceOf(DatabaseLicenceStore::class)
        ->and($store->current())->toBe($key)
        ->and($payload)->not->toContain('pck1.')->not->toContain($key)
        ->and(app('encrypter')->decryptString($payload))->toBe($key)
        ->and(app(LicenceManager::class)->status()->state)->toBe(LicenceState::Valid)
        ->and(is_file($this->licencePath))->toBeFalse();
});

it('keeps the key it replaced as the previous one, and only that one', function () {
    $store = useDatabaseStore();

    $store->replace('one');
    $store->replace('two');
    $store->replace('three');

    expect($store->current())->toBe('three')
        ->and($store->previous())->toBe('two')
        ->and(DB::table('passorde_licences')->count())->toBe(2);
});

it('returns null when nothing is installed', function () {
    $store = useDatabaseStore();

    expect($store->current())->toBeNull()
        ->and($store->previous())->toBeNull()
        ->and($store->lastRefreshAt())->toBeNull()
        ->and(app(LicenceManager::class)->status()->reasonCode)->toBe(LicenceReason::NotInstalled);
});

it('remembers the last refresh, which installing a key by hand does not reset', function () {
    $store = useDatabaseStore();
    $store->replace('one');
    $store->markRefreshed(new DateTimeImmutable('2026-06-15T12:00:00+02:00'));
    $store->replace('two');

    expect($store->lastRefreshAt()?->getTimestamp())->toBe((new DateTimeImmutable('2026-06-15T12:00:00+02:00'))->getTimestamp());
});

it('renews a subscription through the database store', function () {
    useDatabaseStore();
    $old = TestLicence::signed();
    app(LicenceManager::class)->install($old);
    $new = TestLicence::signed(['expires_at' => '2027-12-31']);
    Http::fake(['https://licences.test/licence/lic_test' => Http::response($new."\n", 200, ['Content-Type' => 'text/plain'])]);

    $result = app(LicenceRefresher::class)->refresh();

    expect($result->refreshed)->toBeTrue()
        ->and(app(LicenceStore::class)->current())->toBe($new)
        ->and(app(LicenceStore::class)->previous())->toBe($old)
        ->and(app(LicenceStore::class)->lastRefreshAt())->not->toBeNull()
        ->and(app(LicenceManager::class)->status()->licence?->expiresAt)->toBe('2027-12-31');
});

it('says the store cannot be read when the table is missing, rather than failing', function () {
    licenceTable()->down();
    $store = useDatabaseStore();

    $status = app(LicenceManager::class)->status();

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::StoreUnavailable)
        ->and($status->reason())->toContain('run the migrations')
        ->and(fn () => $store->replace('one'))->toThrow(LicenceStoreException::class)
        ->and(fn () => app(LicenceManager::class)->install(TestLicence::signed()))->toThrow(LicenceStoreException::class);

    $this->get('/passorde/licence')->assertOk()->assertSee('Invalide');
});

it('says the key cannot be decrypted after the application key changed', function () {
    $store = useDatabaseStore();
    $store->replace(TestLicence::signed());

    app()->instance('encrypter', new Encrypter(random_bytes(32), 'aes-256-cbc'));
    app()->forgetInstance(LicenceStore::class);
    app()->forgetInstance(LicenceManager::class);

    $status = app(LicenceManager::class)->status();

    expect($status->reasonCode)->toBe(LicenceReason::StoreUnavailable)
        ->and($status->reason())->toContain('APP_KEY');
});

it('uses the configured connection', function () {
    config()->set('passorde-connect.licence.connection', 'missing');
    $store = useDatabaseStore();

    expect(fn () => $store->current())->toThrow(InvalidArgumentException::class);
    expect(Schema::hasTable('passorde_licences'))->toBeTrue();
});
