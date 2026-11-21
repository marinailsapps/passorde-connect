<?php

use Illuminate\Support\Facades\DB;
use Passorde\Drafts\ClientPatch;
use Passorde\Tests\Support\WriteFake;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Results\Updated;
use Passorde\Writes\UpdateClient;

function clientAfterPatch(): array
{
    return array_merge(WriteFake::fixture('client'), ['phone' => '00 00 00 00 03']);
}

it('sends only what changes: the street replaces all its lines, an empty text clears', function () {
    WriteFake::start([
        'PATCH /clients/601' => [WriteFake::json('client')],
        'GET /contacts-clients' => [WriteFake::page()],
    ]);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(
        name: 'Exemple Test Bis',
        addressLine: "2 chemin de l'Exemple",
        postcode: '00200',
        countryIso2: 'fr',
        phone: '00 00 00 00 03',
        mobile: '',
    ));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->afterUncertainResponse)->toBeFalse()
        ->and($result->record->id)->toBe(601)
        ->and(WriteFake::sent('PATCH /clients/601'))->toBe(WriteFake::fixture('request-update-client'));
});

it('clears the street with addr: null, and takes the cleared street read back for the change', function () {
    $fixture = WriteFake::fixture('client');
    $cleared = array_merge($fixture, ['address' => array_merge($fixture['address'], ['addr' => '', 'addr2' => null, 'addr3' => null])]);
    WriteFake::start([
        'PATCH /clients/601' => [WriteFake::serverError()],
        'GET /clients/601' => [WriteFake::ok($cleared)],
        'GET /contacts-clients' => [WriteFake::page()],
    ]);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(addressLine: ''));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and($result->record->addressLine)->toBeNull()
        ->and(WriteFake::sent('PATCH /clients/601'))->toBe(['address' => ['addr' => null, 'addr2' => null, 'addr3' => null]])
        ->and(WriteFake::times('PATCH /clients/601'))->toBe(1);
});

it('takes a 500 for success when the client read back holds the change', function () {
    WriteFake::start([
        'PATCH /clients/601' => [WriteFake::serverError()],
        'GET /clients/601' => [WriteFake::ok(clientAfterPatch())],
        'GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact')])],
    ]);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(phone: '00 00 00 00 03'));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and($result->record->phone)->toBe('00 00 00 00 03')
        ->and(WriteFake::times('PATCH /clients/601'))->toBe(1)
        ->and(WriteFake::logged('client.verify'))->toHaveCount(1)
        ->and(WriteFake::logged('client.update')[0]->status)->toBe('succeeded');
});

it('compares an address in the shape Evoliz answers: nested country, capitals', function () {
    WriteFake::start([
        'PATCH /clients/601' => [WriteFake::serverError()],
        'GET /clients/601' => [WriteFake::json('client')],
        'GET /contacts-clients' => [WriteFake::page()],
    ]);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(town: 'Exempleville', countryIso2: 'FR', addressLine3: ''));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and(WriteFake::times('PATCH /clients/601'))->toBe(1);
});

it('sends it again when the read shows the change was not applied', function () {
    WriteFake::start([
        'PATCH /clients/601' => [WriteFake::serverError(), WriteFake::ok(clientAfterPatch())],
        'GET /clients/601' => [WriteFake::json('client')],
        'GET /contacts-clients' => [WriteFake::page()],
    ]);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(phone: '00 00 00 00 03'));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->afterUncertainResponse)->toBeFalse()
        ->and(WriteFake::times('PATCH /clients/601'))->toBe(2);
});

it('reads back after a network failure too', function () {
    WriteFake::start([
        'PATCH /clients/601' => [WriteFake::networkFailure()],
        'GET /clients/601' => [WriteFake::ok(clientAfterPatch())],
        'GET /contacts-clients' => [WriteFake::page()],
    ]);

    expect(app(UpdateClient::class)->run(601, new ClientPatch(phone: '00 00 00 00 03')))->toBeInstanceOf(Updated::class)
        ->and(WriteFake::times('PATCH /clients/601'))->toBe(1);
});

it('reads once more when the attempts run out', function () {
    WriteFake::start([
        'PATCH /clients/601' => array_fill(0, 5, WriteFake::serverError()),
        'GET /clients/601' => [...array_fill(0, 4, WriteFake::json('client')), WriteFake::ok(clientAfterPatch())],
        'GET /contacts-clients' => [WriteFake::page()],
    ]);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(phone: '00 00 00 00 03'));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and(WriteFake::times('PATCH /clients/601'))->toBe(5);
});

it('fails in doubt when the change never shows', function () {
    WriteFake::start([
        'PATCH /clients/601' => array_fill(0, 5, WriteFake::serverError()),
        'GET /clients/601' => array_fill(0, 5, WriteFake::json('client')),
    ]);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(phone: '00 00 00 00 03'));

    expect($result)->toBeInstanceOf(Failed::class)->and($result->uncertain)->toBeTrue();
});

it('does not send again while it cannot read, and stays in doubt on a read that is refused', function () {
    WriteFake::start([
        'PATCH /clients/601' => [WriteFake::serverError()],
        'GET /clients/601' => [WriteFake::ok(['error' => 'Forbidden'], 403)],
    ]);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(phone: '00 00 00 00 03'));

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeTrue()
        ->and(WriteFake::times('PATCH /clients/601'))->toBe(1);
});

it('refuses on a 400 or 404 without another attempt', function () {
    WriteFake::start(['PATCH /clients/601' => [WriteFake::ok(['error' => 'Not found'], 404)]]);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(phone: '01'));

    expect($result)->toBeInstanceOf(Refused::class)
        ->and($result->status)->toBe(404)
        ->and(WriteFake::times('PATCH /clients/601'))->toBe(1);
});

it('updates the mirror with the primary contact, which stays active', function () {
    WriteFake::start([
        'PATCH /clients/601' => [WriteFake::ok(clientAfterPatch())],
        'GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact')])],
    ]);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(phone: '00 00 00 00 03'));

    expect($result->record->contact->email)->toBe('camille@exemple.org')
        ->and(DB::table('passorde_clients')->sole()->phone)->toBe('00 00 00 00 03')
        ->and((bool) DB::table('passorde_contacts')->sole()->is_active)->toBeTrue();
});

it('leaves the mirror alone when the contacts cannot be read, so as not to lose the contact', function () {
    WriteFake::start([
        'PATCH /clients/601' => [WriteFake::ok(clientAfterPatch())],
        'GET /contacts-clients' => [WriteFake::serverError()],
    ]);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(phone: '00 00 00 00 03'));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->record->contact)->toBeNull()
        ->and(DB::table('passorde_clients')->count())->toBe(0);
});
