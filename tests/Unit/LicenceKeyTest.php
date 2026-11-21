<?php

use Passorde\Licence\LicenceKey;
use Passorde\Licence\LicenceReason;
use Passorde\Tests\Support\TestLicence;

it('is one line of base64url text after the pck1. prefix', function () {
    $key = TestLicence::signed();

    expect($key)->toStartWith('pck1.')
        ->and(preg_match('/^pck1\.[A-Za-z0-9_-]+\z/', $key))->toBe(1)
        ->and(strlen($key))->toBeLessThan(1000);
});

it('carries the whole signed licence, refresh token included', function () {
    $fields = LicenceKey::decode(TestLicence::signed());

    expect($fields)->toBeInstanceOf(stdClass::class)
        ->and($fields->licence_token)->toBe('token-test')
        ->and($fields->licence_id)->toBe('lic_test')
        ->and($fields->product)->toBe('passorde-connect')
        ->and($fields->signature)->toBeString();
});

it('encodes the same licence to the same key, whatever the field order', function () {
    $json = TestLicence::signedJson();
    $reordered = json_encode(array_reverse(json_decode($json, true), true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect(LicenceKey::fromJson($json))->toBe(LicenceKey::fromJson((string) $reordered))
        ->and(LicenceKey::fromJson($json))->toBe(TestLicence::signed());
});

it('removes every whitespace character when normalising', function () {
    expect(LicenceKey::normalize(" pck1.ab\r\ncd\te f\u{00A0}g \n"))->toBe('pck1.abcdefg');
});

it('tells a JSON licence file apart from a malformed key', function () {
    expect(LicenceKey::decode('  {"kid":"x"}'))->toBe(LicenceReason::LegacyFormat)
        ->and(LicenceKey::decode('hello'))->toBe(LicenceReason::MalformedKey)
        ->and(LicenceKey::decode('pck9.abc'))->toBe(LicenceReason::UnsupportedKeyVersion);
});

it('refuses JSON nested deeper than a licence needs', function () {
    $deep = str_repeat('{"a":', 40).'1'.str_repeat('}', 40);

    expect(LicenceKey::decode('pck1.'.rtrim(strtr(base64_encode($deep), '+/', '-_'), '=')))->toBe(LicenceReason::MalformedKey);
});
