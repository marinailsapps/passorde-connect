<?php

namespace Passorde\Licence;

/**
 * THE place where the production signing keys live. Nothing else in the package, its configuration or the host
 * application can add a key: a licence verifies only if its kid is listed here and its signature matches that key.
 *
 * To put a new production key in service: add one line 'kid' => 'base64 Ed25519 public key (32 bytes)', with the kid
 * the signing service puts in every licence, then release a version. To retire a key: remove its line; every
 * licence signed with it stops verifying ("clé inconnue") in that version.
 *
 * Key id 2026-11 is the production key: only licences issued by the licence service verify.
 * The licence service's test-mode key is never listed here, so a key bought in Stripe's test mode never works.
 */
final class PublicKeys
{
    /**
     * Ed25519 public keys (raw 32 bytes, standard base64), by key id.
     *
     * @var array<string, string>
     */
    public const KEYS = [
        '2026-11' => 'arWixShIiUOpng5pR/TAUhh9LqiFwvxr3opK6IIf5A0=',
    ];
}
