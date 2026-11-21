<?php

use Passorde\Licence\CanonicalJson;
use Passorde\Licence\LicenceKey;
use Passorde\Licence\LicenceState;
use Passorde\Licence\LicenceVerifier;

function licenceVectors(): stdClass
{
    return json_decode((string) file_get_contents(__DIR__.'/../fixtures/licence-vectors.json'), false, 512, JSON_THROW_ON_ERROR);
}

it('has three vectors, one with a non-ASCII company and a slash in the email, one with two connectors', function () {
    $vectors = licenceVectors()->vectors;

    expect($vectors)->toHaveCount(3)
        ->and($vectors[0]->canonical)->toContain('Hélène')->toContain('ventes/achats@')
        ->and($vectors[1]->payload->connectors)->toBe(['evoliz', 'future-connector']);
});

it('holds no secret key material', function () {
    $file = licenceVectors();

    expect(array_keys(get_object_vars($file)))->toBe(['kid', 'public_key', 'vectors'])
        ->and(strlen(base64_decode($file->public_key, true)))->toBe(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
});

it('reproduces the canonical bytes of each vector', function () {
    foreach (licenceVectors()->vectors as $vector) {
        expect(CanonicalJson::encode($vector->payload))->toBe($vector->canonical)
            ->and(base64_decode($vector->canonical_base64, true))->toBe($vector->canonical);
    }
});

it('verifies each signature against the canonical bytes', function () {
    $file = licenceVectors();

    foreach ($file->vectors as $vector) {
        expect(sodium_crypto_sign_verify_detached(
            base64_decode($vector->signature, true),
            base64_decode($vector->canonical_base64, true),
            base64_decode($file->public_key, true),
        ))->toBeTrue();
    }
});

it('builds each key from the payload and its signature, in canonical form', function () {
    foreach (licenceVectors()->vectors as $vector) {
        $licence = clone $vector->payload;
        $licence->signature = $vector->signature;

        expect(LicenceKey::encode($licence))->toBe($vector->key)
            ->and($vector->key)->toStartWith('pck1.')->not->toContain('=')->not->toContain('+')->not->toContain('/');
    }
});

it('accepts each key as a complete licence for the connector the vectors cover', function () {
    $file = licenceVectors();
    $verifier = new LicenceVerifier([$file->kid => $file->public_key]);

    foreach ($file->vectors as $vector) {
        $status = $verifier->verify($vector->key, new DateTimeImmutable('2026-12-01'), 'evoliz');

        expect($status->state)->toBe(LicenceState::Valid)
            ->and($status->licence->product)->toBe('passorde-connect')
            ->and($status->licence->connectors)->toContain('evoliz');
    }
});
