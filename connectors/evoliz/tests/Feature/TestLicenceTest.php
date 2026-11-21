<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\Exceptions\LicenceExpiredException;
use Passorde\Licence\Exceptions\LicenceInvalidException;
use Passorde\Licence\LicenceGuard;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceReason;
use Passorde\Licence\LicenceState;
use Passorde\Licence\LicenceVerifier;
use Passorde\Licence\PublicKeys;
use Passorde\Testing\TestLicence;
use Passorde\Tests\Support\FakeEvoliz;

afterEach(fn () => TestLicence::reset());

it('installs a licence in each state, signed with the test key', function (LicenceState $state, LicenceReason $reason) {
    TestLicence::install($state);

    $status = app(LicenceManager::class)->status();

    expect(is_file($this->licencePath))->toBeTrue()
        ->and($status->state)->toBe($state)
        ->and($status->reasonCode)->toBe($reason);
})->with([
    'valid' => [LicenceState::Valid, LicenceReason::Lifetime],
    'grace' => [LicenceState::Grace, LicenceReason::InGrace],
    'expired' => [LicenceState::Expired, LicenceReason::Expired],
    'invalid' => [LicenceState::Invalid, LicenceReason::BadSignature],
]);

it('signs with kid test and applies the overrides', function () {
    TestLicence::install(overrides: ['plan' => '12m', 'expires_at' => '2026-12-31', 'company' => 'Atelier test']);

    $licence = app(LicenceManager::class)->status()->licence;

    expect($licence->kid)->toBe('test')
        ->and($licence->plan)->toBe('12m')
        ->and($licence->expiresAt)->toBe('2026-12-31')
        ->and($licence->company)->toBe('Atelier test')
        ->and(app(LicenceManager::class)->status()->reasonCode)->toBe(LicenceReason::Active);
});

it('follows the test time for its dates', function () {
    CarbonImmutable::setTestNow('2030-03-01 09:00:00 Europe/Paris');

    TestLicence::install(LicenceState::Grace);

    expect(app(LicenceManager::class)->status()->licence->expiresAt)->toBe('2030-02-26');
});

it('opens and closes the guard of the services resolved before it', function () {
    FakeEvoliz::configure();
    FakeEvoliz::fake([FakeEvoliz::SCOPE.'/clients*' => Http::response(['data' => []])]);
    app(EvolizApi::class);
    app(LicenceGuard::class);

    TestLicence::install(LicenceState::Expired);
    expect(fn () => app(EvolizApi::class)->get('/clients'))->toThrow(LicenceExpiredException::class);

    TestLicence::install();
    expect(app(EvolizApi::class)->get('/clients'))->toBe(['data' => []]);

    TestLicence::missing();
    expect(fn () => app(EvolizApi::class)->get('/clients'))->toThrow(LicenceInvalidException::class)
        ->and(app(LicenceManager::class)->status()->reasonCode)->toBe(LicenceReason::NotInstalled);
});

it('removes the file and goes back to the real keys on reset', function () {
    TestLicence::install();
    TestLicence::install(LicenceState::Grace);

    TestLicence::reset();

    expect(glob($this->licenceDirectory.'/*'))->toBe([])
        ->and(app(LicenceVerifier::class)->verify(TestLicence::signed())->reasonCode)->toBe(LicenceReason::UnknownKid)
        ->and(PublicKeys::KEYS)->not->toHaveKey('test');
});

it('keeps one keypair for the whole process', function () {
    expect(TestLicence::publicKey())->toBe(TestLicence::publicKey());

    TestLicence::install();
    $first = TestLicence::publicKey();
    TestLicence::reset();
    TestLicence::install();

    expect(TestLicence::publicKey())->toBe($first)
        ->and(app(LicenceManager::class)->status()->state)->toBe(LicenceState::Valid);
});

it('gives the raw licence for the settings page', function () {
    TestLicence::install();
    TestLicence::missing();

    $this->post(route('passorde.licence.install'), ['licence' => TestLicence::signed()])->assertRedirect();

    expect(app(LicenceManager::class)->status()->state)->toBe(LicenceState::Valid);
});

it('writes to a temporary file of this process when the host left the default path', function () {
    $default = storage_path('passorde/licence.key');
    config()->set('passorde-connect.licence.path', $default);
    app()->forgetInstance(LicenceStore::class);
    app(LicenceStore::class);

    TestLicence::install();

    expect(app()->runningUnitTests())->toBeTrue()
        ->and(config('passorde-connect.licence.path'))->toBe(TestLicence::temporaryPath())
        ->and(TestLicence::temporaryPath())->toStartWith(sys_get_temp_dir())->toContain((string) getmypid())
        ->and(is_file(TestLicence::temporaryPath()))->toBeTrue()
        ->and(is_file($default))->toBeFalse()
        ->and(app(LicenceManager::class)->status()->state)->toBe(LicenceState::Valid);

    TestLicence::reset();

    expect(is_dir(dirname(TestLicence::temporaryPath())))->toBeFalse();
});

it('keeps a path the host set itself', function () {
    TestLicence::install();

    expect(config('passorde-connect.licence.path'))->toBe($this->licencePath)
        ->and(is_file($this->licencePath))->toBeTrue();
});

it('writes nothing at the default path outside tests', function () {
    $default = storage_path('passorde/licence.key');
    config()->set('passorde-connect.licence.path', $default);
    app()->instance('env', 'local');

    try {
        expect(fn () => TestLicence::install())->toThrow(LogicException::class);
    } finally {
        app()->instance('env', 'testing');
    }

    expect(config('passorde-connect.licence.path'))->toBe($default)
        ->and(is_file($default))->toBeFalse();
});

it('works only in the testing environment', function (string $environment) {
    $previous = app()['env'];
    app()['env'] = $environment;

    try {
        foreach ([fn () => TestLicence::install(), fn () => TestLicence::missing(), fn () => TestLicence::signed()] as $call) {
            expect($call)->toThrow(LogicException::class, 'not in "'.$environment.'"');
        }

        expect(is_file($this->licencePath))->toBeFalse()
            ->and(is_file(TestLicence::temporaryPath()))->toBeFalse();
    } finally {
        app()['env'] = $previous;
    }

    TestLicence::install();
    expect(app(LicenceManager::class)->status()->state)->toBe(LicenceState::Valid);
})->with(['local', 'staging', 'production', 'Testing', 'testing-ci']);
