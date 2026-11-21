<?php

namespace Passorde\Testing;

use Carbon\CarbonImmutable;
use LogicException;
use Passorde\Connectors\ConnectorRegistry;
use Passorde\Licence\CanonicalJson;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\FileLicenceStore;
use Passorde\Licence\LicenceKey;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceRefresher;
use Passorde\Licence\LicenceState;
use Passorde\Licence\LicenceVerifier;
use Passorde\Passorde;

/**
 * A licence for the host application's own tests, signed with a keypair generated in the test process and never
 * stored. Its folder, testing/, is part of every install (production included: composer installs the package's
 * files whole), but it is outside the package's autoloaded code: the class loads only for a host that adds the folder
 * to its own autoload-dev, which composer install --no-dev leaves out.
 *
 *     "autoload-dev": { "psr-4": { "Passorde\\Testing\\": "vendor/marinailsapps/passorde-connect/testing/" } }
 *
 * Then, from setUp(), after the application is booted:
 *
 *     TestLicence::install();                      // or LicenceState::Grace, Expired, Invalid
 *
 * The container's LicenceVerifier then trusts only that key, under the kid "test": a real licence no longer
 * verifies in that test. It runs only when the application's environment is exactly "testing", the environment
 * Laravel's test runner sets: in local, staging, production or any other it throws before trusting anything.
 *
 * It writes through the bound LicenceStore, whatever it is (the database store needs its migration run in the test
 * database). With the file store, when the application runs its unit tests and passorde-connect.licence.path is still
 * the default, install(), missing() and reset() first point that setting at a temporary file of this process, so
 * that the tests never overwrite the key of the host's own development stack.
 */
final class TestLicence
{
    public const KID = 'test';

    /** The one environment TestLicence works in: Laravel's test runner sets it, a deployed application never should. */
    public const ENVIRONMENT = 'testing';

    private const TIMEZONE = 'Europe/Paris';

    /** The services that hold the verifier or the store once resolved, rebuilt on the next resolution. */
    private const HOLDERS = [LicenceManager::class, LicenceRefresher::class];

    /** @var non-empty-string|null */
    private static ?string $keypair = null;

    private static ?string $temporaryPath = null;

    /**
     * The file install() writes to when the host left passorde-connect.licence.path at its default, unique to this process.
     */
    public static function temporaryPath(): string
    {
        return self::$temporaryPath ??= sys_get_temp_dir().DIRECTORY_SEPARATOR.'passorde-test-licence-'.getmypid()
            .'-'.bin2hex(random_bytes(4)).DIRECTORY_SEPARATOR.'licence.key';
    }

    /**
     * Trusts the test key and stores a licence key in that state. Dates follow the current time, Carbon's test time
     * included: Valid is a lifetime licence, Grace expired three days ago (within the 14 days of grace), Expired
     * thirty days ago, and Invalid carries a signature that does not match. It covers every connector registered,
     * unless the overrides say otherwise (connectors => [...]).
     *
     * @param  array<string, mixed>  $overrides  Fields to set or replace before signing, expires_at included.
     */
    public static function install(LicenceState $state = LicenceState::Valid, array $overrides = []): void
    {
        self::refuseOutsideTests();
        self::redirectDefaultPath();
        self::trustTestKey();
        self::store()->replace(self::signed($state, $overrides));
    }

    /**
     * Removes the current licence from a file store, and keeps trusting the test key: the state becomes invalid,
     * reason not installed. With another store, stores an empty key, which reads as not installed too.
     */
    public static function missing(): void
    {
        self::refuseOutsideTests();
        self::redirectDefaultPath();
        self::trustTestKey();

        if (self::store() instanceof FileLicenceStore) {
            self::delete(self::path());
        } else {
            self::store()->replace('');
        }
    }

    /**
     * Removes the licence files that install() may have written and restores the real verifier.
     */
    public static function reset(): void
    {
        self::redirectDefaultPath();
        $path = self::path();

        foreach ([$path, dirname($path).'/licence.previous.key', dirname($path).'/licence.meta.json'] as $file) {
            self::delete($file);
        }

        if ($path === self::temporaryPath() && is_dir(dirname($path))) {
            @rmdir(dirname($path));
        }

        app()->forgetInstance(LicenceVerifier::class);
        self::forgetHolders();
    }

    /**
     * The licence key that install() stores, for a test that installs it some other way, such as the licence page.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function signed(LicenceState $state = LicenceState::Valid, array $overrides = []): string
    {
        self::refuseOutsideTests();

        $fields = array_merge(self::fields($state), $overrides);
        $object = json_decode((string) json_encode($fields), false);
        $signature = sodium_crypto_sign_detached(CanonicalJson::encode($object), sodium_crypto_sign_secretkey(self::keypair()));

        if ($state === LicenceState::Invalid) {
            $signature = str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES);
        }

        $object->signature = base64_encode($signature);

        return LicenceKey::encode($object);
    }

    /**
     * The base64 public key of this process's test keypair.
     */
    public static function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey(self::keypair()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function fields(LicenceState $state): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $major = explode('.', Passorde::VERSION)[0];

        return [
            'kid' => self::KID,
            'licence_id' => 'lic_test',
            'licence_token' => 'test-token',
            'product' => LicenceVerifier::PRODUCT,
            'connectors' => app(ConnectorRegistry::class)->ids() ?: ['test'],
            'plan' => $state === LicenceState::Valid ? 'lifetime' : '12m',
            'email' => 'tests@example.org',
            'company' => 'Test licence',
            'siret' => null,
            'issued_at' => $today->subDays(400)->format('Y-m-d'),
            'expires_at' => match ($state) {
                LicenceState::Valid, LicenceState::Invalid => null,
                LicenceState::Grace => $today->subDays(3)->format('Y-m-d'),
                LicenceState::Expired => $today->subDays(30)->format('Y-m-d'),
            },
            'version_max' => $major.'.x',
            'sites' => 1,
        ];
    }

    private static function refuseOutsideTests(): void
    {
        $environment = app()->environment();

        if ($environment !== self::ENVIRONMENT) {
            throw new LogicException('TestLicence works only in the "testing" environment, not in "'.$environment.'".');
        }
    }

    private static function trustTestKey(): void
    {
        app()->instance(LicenceVerifier::class, new LicenceVerifier([self::KID => self::publicKey()]));
        self::forgetHolders();
    }

    private static function forgetHolders(): void
    {
        foreach (self::HOLDERS as $holder) {
            app()->forgetInstance($holder);
        }

        // A file store keeps the path it was built with: rebuilt, it follows the configuration of this test.
        if (app()->resolved(LicenceStore::class) && app(LicenceStore::class) instanceof FileLicenceStore) {
            app()->forgetInstance(LicenceStore::class);
        }
    }

    private static function store(): LicenceStore
    {
        return app(LicenceStore::class);
    }

    /**
     * In unit tests, moves a default passorde-connect.licence.path to this process's temporary file; the file store
     * is rebuilt on its next resolution so that it follows.
     */
    private static function redirectDefaultPath(): void
    {
        if (! app()->runningUnitTests() || self::path() !== storage_path('passorde/licence.key')) {
            return;
        }

        config()->set('passorde-connect.licence.path', self::temporaryPath());
        self::forgetHolders();
    }

    private static function path(): string
    {
        return (string) config('passorde-connect.licence.path');
    }

    private static function delete(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * @return non-empty-string
     */
    private static function keypair(): string
    {
        return self::$keypair ??= sodium_crypto_sign_keypair();
    }
}
