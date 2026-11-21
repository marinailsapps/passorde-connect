<?php

use Passorde\Api\ApiPath;
use Passorde\Api\Exceptions\InvalidPathException;
use Passorde\Api\ForbiddenPaths;

it('scopes relative paths and leaves /api/ paths alone', function (string $path, string $expected) {
    expect(ApiPath::parse($path)->scopedTo('12345'))->toBe($expected);
})->with([
    ['/clients', '/api/v1/companies/12345/clients'],
    ['clients', '/api/v1/companies/12345/clients'],
    ['/clients/5/contacts', '/api/v1/companies/12345/clients/5/contacts'],
    ['/api/v1/companies/777/clients', '/api/v1/companies/777/clients'],
    ['/apiary', '/api/v1/companies/12345/apiary'],
]);

it('takes a query string out of the path', function () {
    $path = ApiPath::parse('/vat-rates?per_page=1&page=2#top');

    expect($path->path)->toBe('/vat-rates')
        ->and($path->query)->toBe(['per_page' => '1', 'page' => '2']);
});

it('refuses anything that is not a plain path', function (string $path) {
    ApiPath::parse($path);
})->throws(InvalidPathException::class)->with([
    '',
    'https://elsewhere.test/clients',
    'http://evoliz.test/api/v1/clients',
    '/clients/../../999/clients',
    '/clients/%2e%2e/%2e%2e/999',
    './clients',
    "/cli\nents",
    "/cli\x00ents",
    '\\\\elsewhere.test\\clients',
]);

it('matches forbidden paths on whole segments, however they are written', function (string $path, ?string $pattern) {
    $forbidden = new ForbiddenPaths(['/invoices', '/deliveries/{id}/invoice']);

    expect($forbidden->match(ApiPath::parse($path)))->toBe($pattern);
})->with([
    ['/invoices', '/invoices'],
    ['invoices', '/invoices'],
    ['/invoices/12', '/invoices'],
    ['/invoices/', '/invoices'],
    ['//invoices', '/invoices'],
    ['/INVOICES', '/invoices'],
    ['/%69nvoices', '/invoices'],
    ['/%2569nvoices', '/invoices'],
    ['/invoices?page=1', '/invoices'],
    ['/clients/5/invoices', '/invoices'],
    ['/api/v1/companies/12345/invoices', '/invoices'],
    ['/deliveries/7/invoice', '/deliveries/{id}/invoice'],
    ['/deliveries/7/invoice/', '/deliveries/{id}/invoice'],
    ['/deliveries%2F7%2Finvoice', '/deliveries/{id}/invoice'],
    ['/api/v1/companies/12345/Deliveries/7/Invoice', '/deliveries/{id}/invoice'],
    ['/deliveries', null],
    ['/deliveries/7', null],
    ['/deliveries/7/pdf', null],
    ['/invoices-archive', null],
    ['/clients?q=/invoices', null],
]);

it('accepts * as a wildcard and ignores empty patterns', function () {
    expect((new ForbiddenPaths(['/a/*/c']))->match(ApiPath::parse('/a/b/c')))->toBe('/a/*/c')
        ->and((new ForbiddenPaths(['/a/*/c']))->match(ApiPath::parse('/a/c')))->toBeNull();

    config()->set('passorde-connect.evoliz.forbidden_paths', ['', '/', '  ', '/x']);

    expect(ForbiddenPaths::fromConfig()->match(ApiPath::parse('/clients')))->toBeNull()
        ->and(ForbiddenPaths::fromConfig()->match(ApiPath::parse('/x')))->toBe('/x');
});
