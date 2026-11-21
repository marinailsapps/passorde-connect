<?php

use Passorde\Licence\CanonicalJson;

function canonical(string $json): string
{
    return CanonicalJson::encode(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
}

it('drops the signature and sorts keys recursively with no whitespace', function () {
    expect(canonical('{ "b": 1, "signature": "x", "a": { "d": [ { "z": 1, "y": 2 } ], "c": null } }'))
        ->toBe('{"a":{"c":null,"d":[{"y":2,"z":1}]},"b":1}');
});

it('leaves slashes and non-ASCII characters unescaped', function () {
    expect(canonical('{"company":"Hélène 🎈","email":"a/b@exemple.org"}'))
        ->toBe('{"company":"Hélène 🎈","email":"a/b@exemple.org"}');
});

it('writes U+2028 and U+2029 raw, as JSON.stringify does', function () {
    expect(canonical('{"a":"  "}'))->toBe("{\"a\":\"\u{2028}\u{2029}\"}");
});

it('sorts keys by code point', function () {
    expect(canonical('{"🎈":1,"�":2,"é":3,"Z":4,"a":5}'))
        ->toBe('{"Z":4,"a":5,"é":3,"�":2,"🎈":1}');
});

it('keeps empty objects and empty arrays apart', function () {
    expect(canonical('{"b":{},"a":[]}'))->toBe('{"a":[],"b":{}}');
});

it('keeps integer-like keys in an object as an object', function () {
    expect(canonical('{"10":"a","9":"b"}'))->toBe('{"10":"a","9":"b"}');
    expect(canonical('{"0":"a"}'))->toBe('{"0":"a"}');
});
