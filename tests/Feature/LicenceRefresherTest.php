<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Passorde\Connectors\ConnectorRegistry;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceReason;
use Passorde\Licence\LicenceRefresher;
use Passorde\Licence\LicenceService;
use Passorde\Licence\LicenceState;
use Passorde\Licence\LicenceVerifier;
use Passorde\Tests\Support\TestLicence;

const REFRESH_URL = 'https://licences.test/licence/lic_test';

function freshLicence(): string
{
    return TestLicence::signed(['expires_at' => '2027-12-31']);
}

function installedLicence(): string
{
    $raw = TestLicence::signed();
    app(LicenceManager::class)->install($raw);

    return $raw;
}

it('replaces the file with a valid answer', function () {
    $old = installedLicence();
    $new = freshLicence();
    Http::fake([REFRESH_URL => Http::response($new)]);
    Log::spy();

    $result = app(LicenceRefresher::class)->refresh();

    expect($result->refreshed)->toBeTrue()
        ->and(file_get_contents($this->licencePath))->toBe($new)
        ->and(app(LicenceStore::class)->previous())->toBe($old)
        ->and(app(LicenceStore::class)->lastRefreshAt())->not->toBeNull();

    Log::shouldNotHaveReceived('warning');
});

it('sends the expected request', function () {
    installedLicence();
    Http::fake([REFRESH_URL => Http::response(freshLicence())]);

    app(LicenceRefresher::class)->refresh();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === REFRESH_URL
        && $request->header('Authorization') === ['Bearer token-test']
        && $request->header('Accept') === ['text/plain']);
});

it('uses a 10 second timeout and does not follow redirects', function () {
    installedLicence();
    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(freshLicence());
    });

    app(LicenceRefresher::class)->refresh();

    expect($options['timeout'])->toBe(10)
        ->and($options['allow_redirects'])->toBeFalse();
});

it('can refresh a licence that has expired', function () {
    $raw = TestLicence::signed(['expires_at' => '2020-01-31', 'issued_at' => '2020-01-01']);
    app(LicenceManager::class)->install($raw);
    $new = freshLicence();
    Http::fake([REFRESH_URL => Http::response($new)]);

    expect(app(LicenceRefresher::class)->refresh()->refreshed)->toBeTrue()
        ->and(file_get_contents($this->licencePath))->toBe($new);
});

it('can refresh a licence whose version_max no longer covers the package', function () {
    app('files')->ensureDirectoryExists(dirname($this->licencePath));
    file_put_contents($this->licencePath, TestLicence::signed(['version_max' => '0.9']));
    $new = freshLicence();
    Http::fake([REFRESH_URL => Http::response($new)]);

    expect(app(LicenceRefresher::class)->refresh()->refreshed)->toBeTrue()
        ->and(file_get_contents($this->licencePath))->toBe($new);
});

it('keeps the file and logs once on failure', function (Closure $answer) {
    $old = installedLicence();
    Http::fake(['*' => $answer]);
    Log::spy();

    $result = app(LicenceRefresher::class)->refresh();

    expect($result->refreshed)->toBeFalse()
        ->and(file_get_contents($this->licencePath))->toBe($old)
        ->and(app(LicenceStore::class)->previous())->toBeNull()
        ->and(app(LicenceStore::class)->lastRefreshAt())->toBeNull();

    Log::shouldHaveReceived('warning')->once();
})->with([
    'network' => fn () => fn () => throw new ConnectionException('Connection refused'),
    'unexpected exception' => fn () => fn () => throw new RuntimeException('Boom'),
    '401' => fn () => fn () => Http::response('Unauthorized', 401),
    '404' => fn () => fn () => Http::response('', 404),
    '500' => fn () => fn () => Http::response('Oops', 500),
    '204' => fn () => fn () => Http::response('', 204),
    '302' => fn () => fn () => Http::response('', 302, ['Location' => 'https://ailleurs.test/']),
    'bad signature' => fn () => fn () => Http::response(TestLicence::tampered(['expires_at' => '2027-12-31'])),
    'unexpected body' => fn () => fn () => Http::response('<html>Hello</html>'),
    'wrong JSON' => fn () => fn () => Http::response(['ok' => true]),
    'a JSON licence instead of a key' => fn () => fn () => Http::response(TestLicence::signedJson(['expires_at' => '2027-12-31'])),
    'wrong product' => fn () => fn () => Http::response(TestLicence::signed(['product' => 'autre'])),
    'another licence' => fn () => fn () => Http::response(TestLicence::signed(['licence_id' => 'lic_other'])),
]);

it('fails without sending anything when the current licence cannot be verified', function () {
    app('files')->ensureDirectoryExists(dirname($this->licencePath));
    file_put_contents($this->licencePath, TestLicence::tampered());
    Http::fake();
    Log::spy();

    $result = app(LicenceRefresher::class)->refresh();

    expect($result->refreshed)->toBeFalse()
        ->and($result->failed())->toBeTrue();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

it('skips quietly when there is nothing to refresh', function (?Closure $install, string $message) {
    if ($install !== null) {
        $install();
    }
    Http::fake();
    Log::spy();

    $result = app(LicenceRefresher::class)->refresh();

    expect($result->refreshed)->toBeFalse()
        ->and($result->skipped)->toBeTrue()
        ->and($result->failed())->toBeFalse()
        ->and($result->message)->toBe($message)
        ->and(app(LicenceStore::class)->lastRefreshAt())->toBeNull();

    Http::assertNothingSent();
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
})->with([
    'no licence installed' => [null, "Rien à rafraîchir : aucune licence n'est installée."],
    'lifetime licence' => [
        fn () => app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => null, 'licence_token' => null])),
        'Rien à rafraîchir : licence à vie sans jeton de rafraîchissement.',
    ],
    'no token' => [
        fn () => app(LicenceManager::class)->install(TestLicence::signed(without: ['licence_token'])),
        'Rien à rafraîchir : la licence ne contient pas de jeton de rafraîchissement.',
    ],
]);

it('skips a lifetime licence even without a refresh URL', function () {
    config()->set('passorde-connect.licence.refresh_url', null);
    app()->forgetInstance(LicenceRefresher::class);
    app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => null, 'licence_token' => null]));
    Log::spy();

    expect(app(LicenceRefresher::class)->refresh()->skipped)->toBeTrue();

    Log::shouldNotHaveReceived('warning');
});

it('tells whether a licence is refreshable', function (?Closure $install, bool $refreshable) {
    if ($install !== null) {
        $install();
    }

    expect(LicenceRefresher::isRefreshable(app(LicenceManager::class)->status()))->toBe($refreshable);
})->with([
    'dated licence with a token' => [fn () => installedLicence(), true],
    'expired licence with a token' => [
        fn () => app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2020-01-31', 'issued_at' => '2020-01-01'])),
        true,
    ],
    'no licence installed' => [null, false],
    'lifetime licence without a token' => [fn () => app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => null, 'licence_token' => null])), false],
    'lifetime licence with a token' => [fn () => app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => null])), true],
    'no token' => [fn () => app(LicenceManager::class)->install(TestLicence::signed(without: ['licence_token'])), false],
    'empty token' => [fn () => app(LicenceManager::class)->install(TestLicence::signed(['licence_token' => ''])), false],
]);

it('asks the licence service itself when no refresh URL is configured', function (?string $configured) {
    config()->set('passorde-connect.licence.refresh_url', $configured);
    app()->forgetInstance(LicenceRefresher::class);
    installedLicence();
    $new = freshLicence();
    Http::fake([LicenceService::REFRESH_URL.'/licence/lic_test' => Http::response($new)]);
    Log::spy();

    expect(app(LicenceRefresher::class)->refresh()->refreshed)->toBeTrue()
        ->and(file_get_contents($this->licencePath))->toBe($new);

    Http::assertSentCount(1);
    Log::shouldNotHaveReceived('warning');
})->with(['absent' => null, 'empty' => '']);

it('has the licence service as its default address, one constant to change', function () {
    expect(LicenceService::REFRESH_URL)->toStartWith('https://')->not->toEndWith('/')
        ->and((require __DIR__.'/../../config/passorde-connect.php')['licence']['refresh_url'])->toBeNull();
});

it('keeps the key in place when the answer is worse', function (array $current, array $answer, string $reason) {
    app(ConnectorRegistry::class)->register('alpha', 'Passorde Connect — Alpha');
    app(ConnectorRegistry::class)->register('beta', 'Passorde Connect — Beta');
    $old = TestLicence::signed($current);
    app(LicenceStore::class)->replace($old);
    Http::fake([REFRESH_URL => Http::response(TestLicence::signed($answer))]);
    Log::spy();

    $result = app(LicenceRefresher::class)->refresh();

    expect($result->refreshed)->toBeFalse()
        ->and($result->message)->toContain($reason)
        ->and(file_get_contents($this->licencePath))->toBe($old)
        ->and(app(LicenceStore::class)->previous())->toBeNull();

    Log::shouldHaveReceived('warning')->once();
})->with([
    'ends earlier' => [['connectors' => ['alpha']], ['connectors' => ['alpha'], 'expires_at' => '2026-11-30'], 'se termine plus tôt (2026-11-30)'],
    'drops a connector in use' => [['connectors' => ['alpha', 'beta']], ['connectors' => ['alpha'], 'expires_at' => '2027-12-31'], 'ne couvre plus Passorde Connect — Beta'],
    'swaps the connector' => [['connectors' => ['alpha']], ['connectors' => ['beta'], 'expires_at' => '2027-12-31'], 'ne couvre plus Passorde Connect — Alpha'],
    'already expired' => [['connectors' => ['alpha']], ['connectors' => ['alpha'], 'expires_at' => '2026-01-31'], 'se termine plus tôt'],
]);

it('takes an answer that ends on the same day or later, or adds a connector', function (array $answer) {
    app(ConnectorRegistry::class)->register('alpha', 'Passorde Connect — Alpha');
    app(LicenceStore::class)->replace(TestLicence::signed(['connectors' => ['alpha']]));
    $new = TestLicence::signed($answer);
    Http::fake([REFRESH_URL => Http::response($new)]);

    expect(app(LicenceRefresher::class)->refresh()->refreshed)->toBeTrue()
        ->and(file_get_contents($this->licencePath))->toBe($new);
})->with([
    'same day' => [['connectors' => ['alpha'], 'company' => 'Exemple SAS']],
    'later' => [['connectors' => ['alpha'], 'expires_at' => '2027-12-31']],
    'lifetime' => [['connectors' => ['alpha'], 'expires_at' => null]],
    'one more connector' => [['connectors' => ['alpha', 'beta'], 'expires_at' => '2027-12-31']],
]);

it('never writes the token to the log', function () {
    installedLicence();
    Http::fake(['*' => fn () => throw new ConnectionException('Connection refused')]);
    Log::spy();

    app(LicenceRefresher::class)->refresh();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => ! str_contains($message.json_encode($context), 'token-test'),
    );
});

it('refreshes a lifetime licence that carries a token, so an extension of updates arrives by itself', function () {
    $old = TestLicence::signed(['plan' => 'lifetime', 'expires_at' => null, 'updates_until' => '2026-12-31']);
    app(LicenceManager::class)->install($old);
    $new = TestLicence::signed(['plan' => 'lifetime', 'expires_at' => null, 'updates_until' => '2027-12-31']);
    Http::fake([REFRESH_URL => Http::response($new)]);

    expect(app(LicenceRefresher::class)->refresh()->refreshed)->toBeTrue()
        ->and(file_get_contents($this->licencePath))->toBe($new)
        ->and(app(LicenceManager::class)->status()->licence->updatesUntil)->toBe('2027-12-31');

    Http::assertSentCount(1);
});

it('brings back a lifetime key whose updates ended before this release once they are extended', function () {
    // The key in place no longer covers this release (updates ended the day before), so the status is invalid.
    $old = TestLicence::signed(['plan' => 'lifetime', 'expires_at' => null, 'updates_until' => '2026-11-13']);
    app(LicenceStore::class)->replace($old);
    app()->instance(LicenceVerifier::class, TestLicence::verifier(releasedAt: '2026-11-14'));
    app()->forgetInstance(LicenceManager::class);
    app()->forgetInstance(LicenceRefresher::class);
    expect(app(LicenceManager::class)->status()->reasonCode)->toBe(LicenceReason::UpdatesEnded);

    $new = TestLicence::signed(['plan' => 'lifetime', 'expires_at' => null, 'updates_until' => '2027-11-13']);
    Http::fake([REFRESH_URL => Http::response($new)]);

    expect(app(LicenceRefresher::class)->refresh()->refreshed)->toBeTrue()
        ->and(app(LicenceManager::class)->status()->state)->toBe(LicenceState::Valid);
});

it('keeps the key when the answer stops updates earlier, or adds a limit where there was none', function (?string $current, string $answer) {
    $old = TestLicence::signed(['plan' => 'lifetime', 'expires_at' => null, 'updates_until' => $current]);
    app(LicenceManager::class)->install($old);
    Http::fake([REFRESH_URL => Http::response(TestLicence::signed(['plan' => 'lifetime', 'expires_at' => null, 'updates_until' => $answer]))]);
    Log::spy();

    $result = app(LicenceRefresher::class)->refresh();

    expect($result->refreshed)->toBeFalse()
        ->and($result->message)->toContain('arrête les mises à jour plus tôt ('.$answer.')')
        ->and(file_get_contents($this->licencePath))->toBe($old);

    Log::shouldHaveReceived('warning')->once();
})->with([
    'earlier' => ['2027-12-31', '2027-06-30'],
    'a limit where there was none' => [null, '2027-12-31'],
]);

it('keeps the key in place when the refresh of a lifetime key fails', function () {
    $old = TestLicence::signed(['plan' => 'lifetime', 'expires_at' => null, 'updates_until' => '2026-12-31']);
    app(LicenceManager::class)->install($old);
    Http::fake([REFRESH_URL => Http::response('Oops', 500)]);

    expect(app(LicenceRefresher::class)->refresh()->failed())->toBeTrue()
        ->and(file_get_contents($this->licencePath))->toBe($old)
        ->and(app(LicenceManager::class)->status()->state)->toBe(LicenceState::Valid);
});
