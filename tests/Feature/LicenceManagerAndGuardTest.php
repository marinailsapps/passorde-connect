<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\Exceptions\LicenceExpiredException;
use Passorde\Licence\Exceptions\LicenceInvalidException;
use Passorde\Licence\LicenceGuard;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceReason;
use Passorde\Licence\LicenceState;
use Passorde\Tests\Support\TestLicence;

function travelToDate(string $at): void
{
    Carbon::setTestNow($at);
    CarbonImmutable::setTestNow($at);
}

it('reports invalid when no licence is installed', function () {
    expect(app(LicenceManager::class)->status()->reasonCode)->toBe(LicenceReason::NotInstalled);
});

it('installs a valid licence', function () {
    $raw = TestLicence::signed();

    expect(app(LicenceManager::class)->install($raw)->state)->toBe(LicenceState::Valid)
        ->and(file_get_contents($this->licencePath))->toBe($raw);
});

it('installs a licence whose dates have passed, which is not an invalid one', function () {
    $raw = TestLicence::signed(['expires_at' => '2020-01-31', 'issued_at' => '2020-01-01']);

    expect(app(LicenceManager::class)->install($raw)->state)->toBe(LicenceState::Expired)
        ->and(file_get_contents($this->licencePath))->toBe($raw);
});

it('refuses an invalid file and keeps the current one', function (string $bad) {
    $manager = app(LicenceManager::class);
    $good = TestLicence::signed();
    $manager->install($good);

    expect(fn () => $manager->install($bad))->toThrow(LicenceInvalidException::class)
        ->and(file_get_contents($this->licencePath))->toBe($good)
        ->and(app(LicenceStore::class)->previous())->toBeNull();
})->with([
    'not a key' => fn () => 'oops',
    'a licence in JSON form' => fn () => TestLicence::signedJson(),
    'tampered' => fn () => TestLicence::tampered(),
    'wrong product' => fn () => TestLicence::signed(['product' => 'autre']),
    'unknown kid' => fn () => TestLicence::signed(['kid' => '2099-01']),
    'version_max too low' => fn () => TestLicence::signed(['version_max' => '0.9']),
    'issued in the future' => fn () => TestLicence::signed(['issued_at' => '2030-01-01']),
]);

it('reports invalid when the file on disk has been altered', function () {
    app(LicenceManager::class)->install(TestLicence::signed());
    file_put_contents($this->licencePath, TestLicence::tampered());

    expect(app(LicenceManager::class)->status()->reasonCode)->toBe(LicenceReason::BadSignature);
});

it('lets the guard pass on a valid licence', function () {
    app(LicenceManager::class)->install(TestLicence::signed());

    expect(app(LicenceGuard::class)->check()->state)->toBe(LicenceState::Valid);
});

it('lets the guard pass during grace', function () {
    app(LicenceManager::class)->install(TestLicence::signed());
    travelToDate('2027-01-05 12:00:00 Europe/Paris');

    expect(app(LicenceGuard::class)->check()->state)->toBe(LicenceState::Grace);
});

it('makes the guard throw LicenceExpiredException once expired', function () {
    app(LicenceManager::class)->install(TestLicence::signed());
    travelToDate('2027-01-16 00:00:00 Europe/Paris');

    expect(fn () => app(LicenceGuard::class)->check())->toThrow(LicenceExpiredException::class);
});

it('makes the guard throw LicenceInvalidException when invalid', function () {
    expect(fn () => app(LicenceGuard::class)->check())
        ->toThrow(LicenceInvalidException::class, "Aucune licence n'est installée.");
});
