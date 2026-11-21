<?php

/*
 * Writes tests/fixtures/licence-vectors.json. See tests/fixtures/README.md.
 * The keypair exists only while this script runs: the secret key is never written anywhere.
 */

require __DIR__.'/../../vendor/autoload.php';

use Passorde\Licence\CanonicalJson;
use Passorde\Licence\LicenceKey;

$payloads = [
    '3-month plan, non-ASCII company, slash in the email, fields in no particular order' => <<<'JSON'
    {
        "version_max": "1.x",
        "email": "ventes/achats@exemple.org",
        "siret": "00000000000000",
        "company": "Atelier Hélène & Frères — Val-d'Exempleville 🎈",
        "plan": "3m",
        "product": "passorde-connect",
        "connectors": ["evoliz"],
        "licence_id": "lic_vector_0001",
        "licence_token": "token-vector-0001",
        "issued_at": "2026-11-01",
        "expires_at": "2027-02-01",
        "kid": "test-vectors",
        "sites": 1
    }
    JSON,
    'Lifetime plan, null expires_at, updates_until, null optional fields' => <<<'JSON'
    {
        "kid": "test-vectors",
        "licence_id": "lic_vector_0002",
        "licence_token": "token-vector-0002",
        "product": "passorde-connect",
        "connectors": ["evoliz", "future-connector"],
        "plan": "lifetime",
        "email": "contact@exemple.org",
        "company": null,
        "siret": null,
        "issued_at": "2026-11-01",
        "expires_at": null,
        "updates_until": "2027-11-01",
        "version_max": "1.4",
        "sites": 3
    }
    JSON,
    'Unknown extra fields, signed too: nested objects sorted recursively, empty object and array kept apart, key order by code point' => <<<'JSON'
    {
        "kid": "test-vectors",
        "licence_id": "lic_vector_0003",
        "licence_token": "token-vector-0003",
        "product": "passorde-connect",
        "connectors": ["evoliz"],
        "plan": "12m",
        "email": "contact@exemple.org",
        "company": "Exemple SARL",
        "siret": "00000000000000",
        "issued_at": "2026-11-01",
        "expires_at": "2027-11-01",
        "version_max": "1.x",
        "sites": ["a.exemple.org", "b.exemple.org"],
        "zz_extra": {"z": 1, "é": "accent", "a": {"y": [], "x": {}}, "B": true, "🎈": "ballon", "�": "remplacement"}
    }
    JSON,
];

$keypair = sodium_crypto_sign_keypair();
$secretKey = sodium_crypto_sign_secretkey($keypair);
$publicKey = sodium_crypto_sign_publickey($keypair);

$vectors = [];

foreach ($payloads as $description => $json) {
    $canonical = CanonicalJson::encode(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    $signature = base64_encode(sodium_crypto_sign_detached($canonical, $secretKey));
    $signed = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    $signed->signature = $signature;

    $vectors[] = [
        'description' => $description,
        'payload' => json_decode($json, false, 512, JSON_THROW_ON_ERROR),
        'canonical' => $canonical,
        'canonical_base64' => base64_encode($canonical),
        'signature' => $signature,
        'key' => LicenceKey::encode($signed),
    ];
}

sodium_memzero($secretKey);
sodium_memzero($keypair);

file_put_contents(__DIR__.'/licence-vectors.json', json_encode([
    'kid' => 'test-vectors',
    'public_key' => base64_encode($publicKey),
    'vectors' => $vectors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
