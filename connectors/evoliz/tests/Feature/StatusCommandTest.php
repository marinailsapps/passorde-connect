<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Passorde\Licence\LicenceManager;
use Passorde\Tests\Support\TestLicence;

it('exits 0 from passorde:status when valid', function () {
    app(LicenceManager::class)->install(TestLicence::signed());

    $this->artisan('passorde:status')->expectsOutputToContain('Valide')->assertExitCode(0);
});

it('exits 1 from passorde:status during grace', function () {
    app(LicenceManager::class)->install(TestLicence::signed());
    Carbon::setTestNow('2027-01-05 12:00:00 Europe/Paris');
    CarbonImmutable::setTestNow('2027-01-05 12:00:00 Europe/Paris');

    $this->artisan('passorde:status')->expectsOutputToContain('Période de grâce')->assertExitCode(1);
});

it('exits 2 from passorde:status once expired', function () {
    app(LicenceManager::class)->install(TestLicence::signed());
    Carbon::setTestNow('2027-02-01 12:00:00 Europe/Paris');
    CarbonImmutable::setTestNow('2027-02-01 12:00:00 Europe/Paris');

    $this->artisan('passorde:status')->expectsOutputToContain('Expirée')->assertExitCode(2);
});

it('exits 2 from passorde:status when invalid', function () {
    $this->artisan('passorde:status')->expectsOutputToContain('Invalide')->assertExitCode(2);
});
