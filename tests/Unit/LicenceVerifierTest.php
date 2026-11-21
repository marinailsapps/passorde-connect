<?php

use Passorde\Licence\LicenceReason;
use Passorde\Licence\LicenceService;
use Passorde\Licence\LicenceState;
use Passorde\Licence\LicenceStatus;
use Passorde\Licence\LicenceVerifier;
use Passorde\Licence\PublicKeys;
use Passorde\Tests\Support\TestLicence;

function verifyAt(string $raw, string $at): LicenceStatus
{
    return TestLicence::verifier()->verify($raw, new DateTimeImmutable($at));
}

/**
 * With expires_at 2026-12-31, the day ends at 2027-01-01 00:00 Paris and the licence stays valid
 * for 24 more hours. Grace runs for 14 days from 2027-01-02 00:00, so until 2027-01-16 00:00.
 */
function expiringLicence(): string
{
    return TestLicence::signed(['expires_at' => '2026-12-31']);
}

it('accepts a valid licence', function () {
    $status = TestLicence::verifier()->verify(TestLicence::signed());

    expect($status->state)->toBe(LicenceState::Valid)
        ->and($status->reasonCode)->toBe(LicenceReason::Active)
        ->and($status->reason())->toContain('31 décembre 2026')
        ->and($status->licence->licenceId)->toBe('lic_test')
        ->and($status->licence->company)->toBe('Exemple SARL');
});

it('accepts a lifetime licence, which never expires', function () {
    $raw = TestLicence::signed(['expires_at' => null, 'plan' => 'lifetime']);
    $status = verifyAt($raw, '2099-01-01 00:00:00 UTC');

    expect($status->state)->toBe(LicenceState::Valid)
        ->and($status->reasonCode)->toBe(LicenceReason::Lifetime);
});

it('accepts unknown extra fields, which are signed', function () {
    $raw = TestLicence::signed(['future_field' => ['z' => 1, 'a' => new stdClass]]);

    expect(TestLicence::verifier()->verify($raw)->state)->toBe(LicenceState::Valid);
});

it('rejects a licence with one tampered byte', function () {
    $raw = TestLicence::keyOf([...TestLicence::fieldsOf(TestLicence::signed()), 'company' => 'Exemple SARM']);
    $status = TestLicence::verifier()->verify($raw);

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::BadSignature)
        ->and($status->licence)->toBeNull();
});

it('rejects an added field that the signature does not cover', function () {
    $fields = TestLicence::fieldsOf(TestLicence::signed());
    $fields['expires_at'] = null;

    expect(TestLicence::verifier()->verify(TestLicence::keyOf($fields))->reasonCode)
        ->toBe(LicenceReason::BadSignature);
});

it('rejects a malformed signature', function (string $signature) {
    $fields = TestLicence::fieldsOf(TestLicence::signed());
    $fields['signature'] = $signature;

    expect(TestLicence::verifier()->verify(TestLicence::keyOf($fields))->reasonCode)
        ->toBe(LicenceReason::BadSignature);
})->with(['not base64' => '***', 'too short' => base64_encode('short')]);

it('rejects another product', function () {
    $status = TestLicence::verifier()->verify(TestLicence::signed(['product' => 'autre']));

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::WrongProduct);
});

it('rejects an unknown kid and names it', function () {
    $status = TestLicence::verifier()->verify(TestLicence::signed(['kid' => '2099-01']));

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::UnknownKid)
        ->and($status->reason())->toContain('2099-01')->toContain("n'est pas reconnue par cette version de Passorde Connect");
});

it('refuses any kid other than the production one', function () {
    $status = (new LicenceVerifier)->verify(TestLicence::signed(['kid' => '2026-09']));

    expect(PublicKeys::KEYS)->not->toHaveKey('2026-09')
        ->and($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::UnknownKid);
});

it('trusts exactly one production key, kid 2026-11, and never the test-mode one', function () {
    app()->forgetInstance(LicenceVerifier::class);
    $verifier = app(LicenceVerifier::class);

    expect((fn () => $this->publicKeys)->call($verifier))->toBe(['2026-11' => 'arWixShIiUOpng5pR/TAUhh9LqiFwvxr3opK6IIf5A0='])
        ->and(PublicKeys::KEYS)->not->toHaveKey('test-2026-11')
        ->and(PublicKeys::KEYS)->not->toContain('hm7bagcCkKkNsamHFZ2dhQwSfewyx1nCa4mX0qTUzVg=');

    // A key that claims kid 2026-11 but is signed with any other key fails its signature.
    expect($verifier->verify(TestLicence::signed(['kid' => '2026-11']))->reasonCode)->toBe(LicenceReason::BadSignature)
        ->and($verifier->verify(TestLicence::signed(['kid' => 'test-2026-11']))->reasonCode)->toBe(LicenceReason::UnknownKid);
});

it('trusts by default only the keys fixed in PublicKeys, each a 32-byte Ed25519 key', function () {
    foreach (PublicKeys::KEYS as $kid => $key) {
        expect($kid)->toBeString()->not->toBe('')
            ->and(strlen((string) base64_decode($key, true)))->toBe(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
    }

    expect((new LicenceVerifier)->verify(TestLicence::signed())->reasonCode)->toBe(LicenceReason::UnknownKid);
});

it('rejects a missing required field', function (string $field) {
    $status = TestLicence::verifier()->verify(TestLicence::signed(without: [$field]));

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::MissingField)
        ->and($status->reason())->toContain($field);
})->with(['kid', 'licence_id', 'product', 'connectors', 'plan', 'issued_at', 'expires_at', 'version_max']);

it('rejects a licence with no signature', function () {
    $fields = TestLicence::fieldsOf(TestLicence::signed());
    unset($fields['signature']);
    $status = TestLicence::verifier()->verify(TestLicence::keyOf($fields));

    expect($status->reasonCode)->toBe(LicenceReason::MissingField)
        ->and($status->reason())->toContain('signature');
});

it('rejects a required field of the wrong type', function (string $field, mixed $value) {
    expect(TestLicence::verifier()->verify(TestLicence::signed([$field => $value]))->reasonCode)
        ->toBe(LicenceReason::InvalidField);
})->with([
    ['licence_id', 12],
    ['plan', ''],
    ['issued_at', null],
    ['expires_at', 20261231],
    ['version_max', 1.4],
    'connectors not a list' => ['connectors', 'acme'],
    'connectors empty' => ['connectors', []],
    'connectors with a bad id' => ['connectors', ['Acme']],
    'connectors with a number' => ['connectors', [1]],
    'connectors twice the same' => ['connectors', ['acme', 'acme']],
    'connectors as an object' => ['connectors', ['a' => 'acme']],
]);

it('rejects text that is not a licence key', function (string $raw) {
    $status = TestLicence::verifier()->verify($raw);

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::MalformedKey)
        ->and($status->reason())->toContain('pck1.');
})->with([
    'empty' => '',
    'no prefix' => 'abc',
    'prefix only' => 'pck1.',
    'not base64url' => 'pck1.ab+c/d==',
    'a lone trailing character' => 'pck1.abcde',
    'not JSON' => 'pck1.'.rtrim(strtr(base64_encode('{not json'), '+/', '-_'), '='),
    'a JSON string' => 'pck1.'.rtrim(strtr(base64_encode('"text"'), '+/', '-_'), '='),
    'a JSON list' => 'pck1.'.rtrim(strtr(base64_encode('[1,2]'), '+/', '-_'), '='),
    'too long' => 'pck1.'.str_repeat('A', 20000),
]);

it('refuses a licence in JSON form, with a reason that says so', function () {
    $status = TestLicence::verifier()->verify(TestLicence::signedJson());

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::LegacyFormat)
        ->and($status->reason())->toContain('licence au format JSON');
});

it('refuses a key of a later format version', function () {
    $status = TestLicence::verifier()->verify('pck2.'.substr(TestLicence::signed(), 5));

    expect($status->reasonCode)->toBe(LicenceReason::UnsupportedKeyVersion)
        ->and($status->reason())->toContain('mettez le paquet à jour');
});

it('ignores whitespace anywhere in the key, as a wrapped e-mail line has it', function () {
    $key = TestLicence::signed();
    $wrapped = "  \n".implode("\r\n", str_split($key, 60))."\t\n";

    expect(TestLicence::verifier()->verify($wrapped)->state)->toBe(LicenceState::Valid);
});

it('accepts the prefix in capitals', function () {
    expect(TestLicence::verifier()->verify('PCK1.'.substr(TestLicence::signed(), 5))->state)->toBe(LicenceState::Valid);
});

it('checks the connector in use against the signed list', function () {
    $raw = TestLicence::signed(['connectors' => ['acme', 'other']]);

    expect(TestLicence::verifier()->verify($raw, connector: 'acme')->state)->toBe(LicenceState::Valid)
        ->and(TestLicence::verifier()->verify($raw, connector: 'other')->state)->toBe(LicenceState::Valid)
        ->and(TestLicence::verifier()->verify($raw)->state)->toBe(LicenceState::Valid);

    $status = TestLicence::verifier()->verify($raw, connector: 'third');

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::ConnectorNotCovered)
        ->and($status->licence?->connectors)->toBe(['acme', 'other'])
        ->and($status->reason())->toBe('Cette licence ne couvre pas le connecteur third (connecteurs couverts : acme, other).');
});

it('refuses a connector not covered even when the licence has expired', function () {
    $raw = TestLicence::signed(['connectors' => ['acme'], 'expires_at' => '2020-01-31']);

    expect(TestLicence::verifier()->verify($raw, connector: 'other')->reasonCode)->toBe(LicenceReason::ConnectorNotCovered)
        ->and(TestLicence::verifier()->verify($raw, connector: 'acme')->state)->toBe(LicenceState::Expired);
});

it('rejects a connectors list changed after signing', function () {
    $fields = TestLicence::fieldsOf(TestLicence::signed(['connectors' => ['acme']]));
    $fields['connectors'] = ['acme', 'other'];

    expect(TestLicence::verifier()->verify(TestLicence::keyOf($fields), connector: 'other')->reasonCode)
        ->toBe(LicenceReason::BadSignature);
});

it('names the product in a wrong-product reason', function () {
    $status = TestLicence::verifier()->verify(TestLicence::signed(['product' => 'passorde']));

    expect($status->reasonCode)->toBe(LicenceReason::WrongProduct)
        ->and($status->reason())->toBe('Cette licence concerne un autre produit (passorde), pas Passorde Connect.');
});

it('rejects a malformed date', function (string $field, string $value) {
    $status = TestLicence::verifier()->verify(TestLicence::signed([$field => $value]));

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::MalformedDate)
        ->and($status->reason())->toContain($field);
})->with([
    'wrong separator' => ['expires_at', '2026/12/31'],
    'not a real day' => ['expires_at', '2026-02-30'],
    'month 13' => ['expires_at', '2026-13-01'],
    'with a time' => ['expires_at', '2026-12-31T00:00:00Z'],
    'trailing newline' => ['expires_at', "2026-12-31\n"],
    'issued_at' => ['issued_at', '01/01/2026'],
]);

it('rejects a licence issued more than 24 hours in the future', function () {
    $raw = TestLicence::signed(['issued_at' => '2026-06-17']);

    $status = verifyAt($raw, '2026-06-15 23:59:59 Europe/Paris');

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::IssuedInFuture);
});

it('tolerates issued_at up to exactly 24 hours in the future', function () {
    $raw = TestLicence::signed(['issued_at' => '2026-06-17']);

    expect(verifyAt($raw, '2026-06-16 00:00:00 Europe/Paris')->state)->toBe(LicenceState::Valid);
});

it('applies the issued_at check to a lifetime licence too', function () {
    $raw = TestLicence::signed(['issued_at' => '2026-07-01', 'expires_at' => null]);

    expect(TestLicence::verifier()->verify($raw)->reasonCode)->toBe(LicenceReason::IssuedInFuture);
});

it('is valid 24 hours before the end of the expiry day', function () {
    expect(verifyAt(expiringLicence(), '2026-12-31 00:00:00 Europe/Paris')->state)->toBe(LicenceState::Valid);
});

it('is valid at the last second of the expiry day', function () {
    expect(verifyAt(expiringLicence(), '2026-12-31 23:59:59 Europe/Paris')->state)->toBe(LicenceState::Valid);
});

it('is valid up to the last second of the last paid day, with no tolerance after it', function () {
    expect(verifyAt(expiringLicence(), '2026-12-31 23:59:59 Europe/Paris')->state)->toBe(LicenceState::Valid);
});

it('enters grace the instant the paid period ends, 00:00 Paris the next day', function () {
    $status = verifyAt(expiringLicence(), '2027-01-01 00:00:00 Europe/Paris');

    expect($status->state)->toBe(LicenceState::Grace)
        ->and($status->reasonCode)->toBe(LicenceReason::InGrace)
        ->and($status->reason())->toBe("La période payée s'est terminée le 31 décembre 2026 ; la période de grâce court jusqu'au 14 janvier 2027 inclus. "
            ."Renouvelez la licence sur https://apps.marinails.site/passorde, puis installez la clé reçue par e-mail ; quand le planificateur de tâches tourne, le rafraîchissement quotidien l'installe aussi de lui-même.")
        ->and($status->graceEndsAt?->format(DATE_ATOM))->toBe('2027-01-15T00:00:00+01:00')
        ->and($status->graceLastDay())->toBe('2027-01-14')
        ->and($status->params)->toMatchArray(['until' => '2027-01-15 00:00', 'last_day' => '2027-01-14']);
});

it('names the last full day of grace, not the first day after it', function () {
    // Expired on 2026-11-04: grace from 11-05 00:00 for 14 calendar days, so until 11-19 00:00.
    $raw = TestLicence::signed(['expires_at' => '2026-11-04']);
    $status = verifyAt($raw, '2026-11-07 12:00:00 Europe/Paris');

    expect($status->state)->toBe(LicenceState::Grace)
        ->and($status->reason())->toContain("jusqu'au 18 novembre 2026 inclus")->not->toContain('19 novembre')
        ->and($status->graceEndsAt?->format('Y-m-d H:i'))->toBe('2026-11-19 00:00')
        ->and(verifyAt($raw, '2026-11-18 23:59:59 Europe/Paris')->state)->toBe(LicenceState::Grace)
        ->and(verifyAt($raw, '2026-11-19 00:00:00 Europe/Paris')->state)->toBe(LicenceState::Expired);
});

it('counts the 14 days of grace as calendar days in Paris across a daylight saving change', function () {
    // Clocks go back on 2026-10-25 (a 25 hour day): grace still ends at 00:00 Paris, 14 calendar days on.
    $raw = TestLicence::signed(['expires_at' => '2026-10-20']);
    $status = verifyAt($raw, '2026-11-01 12:00:00 Europe/Paris');

    expect($status->graceEndsAt?->format('Y-m-d H:i T'))->toBe('2026-11-04 00:00 CET')
        ->and($status->graceLastDay())->toBe('2026-11-03')
        ->and(verifyAt($raw, '2026-11-03 23:59:59 Europe/Paris')->state)->toBe(LicenceState::Grace)
        ->and(verifyAt($raw, '2026-11-04 00:00:00 Europe/Paris')->state)->toBe(LicenceState::Expired);
});

it('gives the grace end of an expired licence and none of a valid one', function () {
    expect(verifyAt(expiringLicence(), '2027-01-16 00:00:00 Europe/Paris')->graceEndsAt?->format('Y-m-d H:i'))->toBe('2027-01-15 00:00')
        ->and(verifyAt(expiringLicence(), '2026-06-15 12:00:00 Europe/Paris')->graceEndsAt)->toBeNull()
        ->and(verifyAt(expiringLicence(), '2026-06-15 12:00:00 Europe/Paris')->graceLastDay())->toBeNull();
});

it('uses the end of the day in Europe/Paris, not UTC', function () {
    // 2026-12-31 22:59:59 UTC is 23:59:59 in Paris on the last paid day, a second later grace starts.
    expect(verifyAt(expiringLicence(), '2026-12-31 22:59:59 UTC')->state)->toBe(LicenceState::Valid)
        ->and(verifyAt(expiringLicence(), '2026-12-31 23:00:00 UTC')->state)->toBe(LicenceState::Grace);
});

it('follows daylight saving in Europe/Paris', function () {
    // Clocks go back on 2026-10-25, a 25 hour day. The day ends at 2026-10-26 00:00 CET (23:00 UTC on the 25th).
    $raw = TestLicence::signed(['expires_at' => '2026-10-25']);

    expect(verifyAt($raw, '2026-10-25 22:59:59 UTC')->state)->toBe(LicenceState::Valid)
        ->and(verifyAt($raw, '2026-10-25 23:00:00 UTC')->state)->toBe(LicenceState::Grace);
});

it('is in grace on grace day 1', function () {
    expect(verifyAt(expiringLicence(), '2027-01-01 12:00:00 Europe/Paris')->state)->toBe(LicenceState::Grace);
});

it('is in grace on grace day 14', function () {
    expect(verifyAt(expiringLicence(), '2027-01-14 23:59:59 Europe/Paris')->state)->toBe(LicenceState::Grace);
});

it('is expired on day 15', function () {
    $status = verifyAt(expiringLicence(), '2027-01-15 00:00:00 Europe/Paris');

    expect($status->state)->toBe(LicenceState::Expired)
        ->and($status->reasonCode)->toBe(LicenceReason::Expired)
        ->and($status->reason())->toContain('31 décembre 2026')->toContain('https://apps.marinails.site/passorde,');
});

it('sends buyers to the Passorde page, where the connectors are sold', function () {
    expect(LicenceService::SITE_URL)->toBe('https://apps.marinails.site/passorde');
});

it('accepts a covering version_max', function (string $versionMax, string $package) {
    expect(TestLicence::verifier($package)->verify(TestLicence::signed(['version_max' => $versionMax]))->state)
        ->toBe(LicenceState::Valid);
})->with([
    ['1.x', '1.0.0-dev'],
    ['1.x', '1.99.3'],
    ['1.4', '1.0.0-dev'],
    ['1.4', '1.4.0'],
    ['1.4', '1.4.9'],
    ['2.0', '1.9.0'],
    ['1.x', '1.0.0-beta.1'],
    ['1.0', '1.0.0-beta.1'],
    ['1.0', '1.0.0-rc.2+build.5'],
    ['1.4', '1.4.0-alpha'],
    ['2.x', '2.0.0-beta.1'],
    ['2.0', '2.0.0-beta.1'],
    ['1.10', '1.9.0'],
]);

it('rejects a version_max below the package version', function (string $versionMax, string $package) {
    $status = TestLicence::verifier($package)->verify(TestLicence::signed(['version_max' => $versionMax]));

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::VersionExceeded)
        ->and($status->reason())->toContain($versionMax)->toContain($package);
})->with([
    ['0.9', '1.0.0-dev'],
    ['0.x', '1.0.0-dev'],
    ['1.4', '1.5.0'],
    ['1.x', '2.0.0'],
    ['1.4', '2.0.0'],
    'a pre-release of a later minor' => ['1.4', '1.5.0-beta.1'],
    'a pre-release of a later major' => ['1.x', '2.0.0-beta.1'],
    'a pre-release below the licence is no exception' => ['0.x', '1.0.0-beta.1'],
    'numbers, not text' => ['1.9', '1.10.0-rc.1'],
]);

it('rejects a version_max in an unknown format', function (string $versionMax) {
    expect(TestLicence::verifier()->verify(TestLicence::signed(['version_max' => $versionMax]))->reasonCode)
        ->toBe(LicenceReason::BadVersionMax);
})->with(['1', '1.4.2', 'latest', '*', "1.x\n", '1.0.0-beta.1', '1.x-beta']);

it('takes no chance on a package version it cannot read', function (string $package) {
    expect(TestLicence::verifier($package)->verify(TestLicence::signed())->state)->toBe(LicenceState::Invalid);
})->with(['1.0', '1.0.0garbage', 'v1.0.0', '1.0.0-', 'dev-main', '']);

it('compares version_max against the real package version', function () {
    expect(Passorde\Passorde::VERSION)->toBe('1.0.0-beta.5')
        ->and(TestLicence::verifier()->verify(TestLicence::signed(['version_max' => '1.x']))->state)->toBe(LicenceState::Valid)
        ->and(TestLicence::verifier()->verify(TestLicence::signed(['version_max' => '1.0']))->state)->toBe(LicenceState::Valid)
        ->and(TestLicence::verifier()->verify(TestLicence::signed(['version_max' => '0.x']))->state)->toBe(LicenceState::Invalid);
});

it('has a changelog entry for the package version, with its release date', function () {
    expect(file_get_contents(dirname(__DIR__, 2).'/CHANGELOG.md'))->toContain('## '.Passorde\Passorde::VERSION.' ('.Passorde\Passorde::RELEASED_AT.')')
        ->and(Passorde\Passorde::RELEASED_AT)->toMatch('/^\d{4}-\d{2}-\d{2}\z/');
});

function lifetimeWithUpdates(?string $until): string
{
    return TestLicence::signed(['plan' => 'lifetime', 'expires_at' => null, 'updates_until' => $until]);
}

it('keeps a key without updates_until valid, as subscriptions have it', function () {
    $subscription = TestLicence::signed(without: ['updates_until']);

    expect(TestLicence::verifier(releasedAt: '2099-01-01')->verify($subscription)->state)->toBe(LicenceState::Valid)
        ->and(TestLicence::verifier(releasedAt: '2099-01-01')->verify(lifetimeWithUpdates(null))->reasonCode)->toBe(LicenceReason::Lifetime)
        ->and(TestLicence::verifier()->verify($subscription)->licence?->updatesUntil)->toBeNull();
});

it('keeps a lifetime key whose updates ended working, without end date, on a release from before', function (string $released) {
    $status = TestLicence::verifier(releasedAt: $released)->verify(lifetimeWithUpdates('2026-03-31'));

    expect($status->state)->toBe(LicenceState::Valid)
        ->and($status->reasonCode)->toBe(LicenceReason::Lifetime)
        ->and($status->licence?->updatesUntil)->toBe('2026-03-31');
})->with(['released long before' => '2025-12-01', 'released on the last day of updates' => '2026-03-31']);

it('refuses the same key on a release from after its updates ended, with the way back', function () {
    $status = TestLicence::verifier('1.2.0', '2026-04-01')->verify(lifetimeWithUpdates('2026-03-31'));

    expect($status->state)->toBe(LicenceState::Invalid)
        ->and($status->reasonCode)->toBe(LicenceReason::UpdatesEnded)
        ->and($status->isUsable())->toBeFalse()
        ->and($status->licence?->updatesUntil)->toBe('2026-03-31')
        ->and($status->params)->toMatchArray(['date' => '2026-03-31', 'released' => '2026-04-01', 'before' => '2026-04-01'])
        ->and($status->reason())->toBe("Les mises à jour de cette licence couvrent les versions publiées jusqu'au 31 mars 2026 inclus ; la version installée (1.2.0) a été publiée le 1er avril 2026. "
            .'Prolongez les mises à jour sur https://apps.marinails.site/passorde, ou installez la clé reçue par e-mail, ou revenez à une version publiée au plus tard le 31 mars 2026. '
            .'Quand le planificateur de tâches tourne, le rafraîchissement quotidien installe aussi de lui-même la clé prolongée.');
});

it('applies updates_until to a dated key too, before its dates', function () {
    $raw = TestLicence::signed(['updates_until' => '2026-03-31']);

    expect(TestLicence::verifier(releasedAt: '2026-04-01')->verify($raw)->reasonCode)->toBe(LicenceReason::UpdatesEnded)
        ->and(TestLicence::verifier(releasedAt: '2026-03-31')->verify($raw)->state)->toBe(LicenceState::Valid);
});

it('refuses an updates_until it cannot read, and takes no chance on a release date it cannot read', function () {
    expect(TestLicence::verifier()->verify(lifetimeWithUpdates('31/03/2026'))->reasonCode)->toBe(LicenceReason::MalformedDate)
        ->and(TestLicence::verifier()->verify(lifetimeWithUpdates('2026-02-30'))->params)->toBe(['field' => 'updates_until'])
        ->and(TestLicence::verifier()->verify(TestLicence::signed(['updates_until' => 20260331]))->reasonCode)->toBe(LicenceReason::InvalidField)
        ->and(TestLicence::verifier(releasedAt: 'soon')->verify(lifetimeWithUpdates('2026-03-31'))->params)->toBe(['field' => 'released_at']);
});

it('rejects an updates_until changed after signing', function () {
    $tampered = TestLicence::keyOf([...TestLicence::fieldsOf(lifetimeWithUpdates('2026-03-31')), 'updates_until' => '2099-12-31']);

    expect(TestLicence::verifier()->verify($tampered)->reasonCode)->toBe(LicenceReason::BadSignature);
});

it('writes days in French in the texts and keeps YYYY-MM-DD in the params', function (string $day, string $french) {
    expect(LicenceStatus::frenchDay($day))->toBe($french);
})->with([
    ['2027-01-01', '1er janvier 2027'],
    ['2026-11-14', '14 novembre 2026'],
    ['2026-08-31', '31 août 2026'],
    ['2026-02-30', '2026-02-30'],
    ['bientôt', 'bientôt'],
]);

it('keeps the keys valid on 1.0.0-beta.3 valid on this version', function () {
    // 1.0.0-beta.3 was released on 2026-11-14: a lifetime key sold that day with 12 months of updates, or a key
    // without updates_until, must not be refused by a later release date.
    $current = TestLicence::verifier();

    expect($current->verify(lifetimeWithUpdates('2027-11-14'))->reasonCode)->toBe(LicenceReason::Lifetime)
        ->and($current->verify(TestLicence::signed(without: ['updates_until']))->state)->toBe(LicenceState::Valid)
        ->and($current->verify(TestLicence::signed(['updates_until' => '2027-11-14']))->state)->toBe(LicenceState::Valid);
});

it('has a release date that is not in the future', function () {
    // The real clock, not the tests' fixed one: a date set ahead would refuse lifetime keys whose updates still run
    // on the day the version is actually published.
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');

    expect($today >= Passorde\Passorde::RELEASED_AT)->toBeTrue();
});
