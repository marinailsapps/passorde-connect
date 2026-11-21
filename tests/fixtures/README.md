# Licence test vectors

`licence-vectors.json` lets the signing service check its output against this package.

## How the vectors were produced

`php tests/fixtures/generate-vectors.php`, after `composer install`. The script generates a throwaway Ed25519 keypair, canonicalises each payload with `Passorde\Licence\CanonicalJson`, signs it, builds its licence key with `Passorde\Licence\LicenceKey`, and writes the public key and the results. The secret key is never written anywhere, and running the script again replaces the key and every signature. This key is for tests only and is unrelated to any production key.

## What each vector holds

- `payload`: the licence fields without `signature`, deliberately not in canonical order;
- `canonical`: the bytes that are signed, as a string;
- `canonical_base64`: the same bytes in base64, free of any JSON string escaping;
- `signature`: the Ed25519 signature of those bytes, in base64;
- `key`: the licence key (`pck1.` + base64url without padding of the canonical JSON with the signature included), as the signing service must produce it.

Every payload has `product: "passorde-connect"` and a `connectors` list; the second lists two connectors. The connector id `evoliz` stands for the product Passorde Connect — Evoliz, compatible avec Evoliz, non affilié à Evoliz SAS / Visma.

## How to use them in the signing service

For each vector, canonicalise `payload` and compare the result byte for byte with `canonical_base64`, then verify `signature` against the canonical bytes with `public_key`, then build the key from `payload` and `signature` and compare it with `key`. Format and refresh contract: `docs/licence-key.md`. The service cannot reproduce the signatures themselves because the secret key was discarded; its own signatures can be checked by installing a licence signed with a key under test.

## Canonicalisation traps in JavaScript

- `Array.prototype.sort()` compares UTF-16 code units, not code points: `🎈` (U+1F388) sorts before `�` (U+FFFD) in JavaScript but after it by code point. The third vector covers this.
- A JavaScript object always lists integer-like keys first, so build the canonical string by hand rather than from a sorted object if a key can look like an integer.
- Do not put floating-point numbers in a licence: PHP and JavaScript can format very large or very small ones differently.
