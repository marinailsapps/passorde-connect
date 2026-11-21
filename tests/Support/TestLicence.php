<?php

namespace Passorde\Tests\Support;

use Passorde\Connectors\ConnectorRegistry;
use Passorde\Licence\CanonicalJson;
use Passorde\Licence\LicenceKey;
use Passorde\Licence\LicenceVerifier;
use Passorde\Passorde;

/**
 * Signs licences with a keypair generated for this test run and never stored.
 */
final class TestLicence
{
    public const KID = 'test-run';

    public const NOW = '2026-06-15 12:00:00 Europe/Paris';

    private static ?string $keypair = null;

    /**
     * @return array<string, mixed>
     */
    public static function fields(array $overrides = []): array
    {
        return array_merge([
            'kid' => self::KID,
            'licence_id' => 'lic_test',
            'licence_token' => 'token-test',
            'product' => 'passorde-connect',
            'connectors' => self::connectors(),
            'plan' => '12m',
            'email' => 'contact@exemple.org',
            'company' => 'Exemple SARL',
            'siret' => '00000000000000',
            'issued_at' => '2026-01-01',
            'expires_at' => '2026-12-31',
            'version_max' => '1.x',
            'sites' => 1,
        ], $overrides);
    }

    /**
     * The licence key (pck1.…).
     */
    public static function signed(array $overrides = [], array $without = []): string
    {
        return self::sign(array_diff_key(self::fields($overrides), array_flip($without)));
    }

    /**
     * The same licence as a JSON object, a form the package refuses.
     */
    public static function signedJson(array $overrides = [], array $without = []): string
    {
        return self::signJson(array_diff_key(self::fields($overrides), array_flip($without)));
    }

    public static function sign(array $fields): string
    {
        return LicenceKey::fromJson(self::signJson($fields));
    }

    /**
     * The connectors registered in the application under test; acme where none is (core tests).
     *
     * @return list<string>
     */
    public static function connectors(): array
    {
        return app(ConnectorRegistry::class)->ids() ?: ['acme'];
    }

    public static function signJson(array $fields): string
    {
        $object = json_decode((string) json_encode($fields), false);
        $canonical = CanonicalJson::encode($object);
        $object->signature = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            sodium_crypto_sign_secretkey(self::keypair()),
        ));

        return (string) json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The fields a key carries, signature included, to tamper with in a test.
     */
    public static function fieldsOf(string $key): array
    {
        return json_decode((string) json_encode(LicenceKey::decode($key)), true);
    }

    /**
     * A key carrying these fields as they are, not signed again.
     */
    public static function keyOf(array $fields): string
    {
        return LicenceKey::fromJson((string) json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * A key whose company was changed after signing.
     */
    public static function tampered(array $overrides = []): string
    {
        return self::keyOf([...self::fieldsOf(self::signed($overrides)), 'company' => 'Exemple SARM']);
    }

    public static function verifier(string $version = Passorde::VERSION, string $releasedAt = Passorde::RELEASED_AT): LicenceVerifier
    {
        return new LicenceVerifier([self::KID => self::publicKey()], $version, $releasedAt);
    }

    /**
     * The base64 public key of this run's keypair, for a test that tries to slip it in as a trusted key.
     */
    public static function verifierKey(): string
    {
        return self::publicKey();
    }

    private static function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey(self::keypair()));
    }

    private static function keypair(): string
    {
        return self::$keypair ??= sodium_crypto_sign_keypair();
    }
}
