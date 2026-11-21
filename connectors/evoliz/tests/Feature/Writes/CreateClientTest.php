<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Passorde\Api\Exceptions\WritesDisabledException;
use Passorde\Drafts\ClientDraft;
use Passorde\Records\ClientRecord;
use Passorde\Tests\Support\WriteFake;
use Passorde\Writes\CreateClient;
use Passorde\Writes\Results\AlreadyExists;
use Passorde\Writes\Results\Created;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;

function clientDraft(array $overrides = []): ClientDraft
{
    return new ClientDraft(...array_merge([
        'name' => 'Exemple Test',
        'addressLine' => "1 chemin de l'Exemple",
        'addressLine2' => 'Bâtiment B',
        'postcode' => '00100',
        'town' => 'EXEMPLEVILLE',
        'phone' => '00 00 00 00 01',
        'mobile' => '00 00 00 00 02',
    ], $overrides));
}

it('creates a client: code from the name, VAT number NC, address block, phones', function () {
    WriteFake::start([
        'GET /clients' => [WriteFake::page()],
        'POST /clients' => [WriteFake::json('client', 201)],
    ]);

    $result = app(CreateClient::class)->run(clientDraft());

    expect($result)->toBeInstanceOf(Created::class)
        ->and($result->succeeded())->toBeTrue()
        ->and($result->record)->toBeInstanceOf(ClientRecord::class)
        ->and($result->record->id)->toBe(601)
        ->and($result->record->countryIso2)->toBe('FR')
        ->and(WriteFake::trail())->toBe([
            'GET /clients?code=EXEMPLETES&per_page=100&page=1',
            'POST /clients',
        ])
        ->and(WriteFake::sent('POST /clients'))->toBe(WriteFake::fixture('request-create-client'));
});

it('creates a client with no street, postcode or town, ', function () {
    $draft = new ClientDraft(name: 'Exemple Test');
    WriteFake::start([
        'GET /clients' => [WriteFake::page()],
        'POST /clients' => [WriteFake::ok(array_merge(WriteFake::fixture('client'), ['address' => ['addr' => null, 'postcode' => null, 'town' => null, 'country' => ['iso2' => 'FR']]]), 201)],
    ]);

    $result = app(CreateClient::class)->run($draft);

    expect($result)->toBeInstanceOf(Created::class)
        ->and($result->record->postcode)->toBeNull()
        ->and(WriteFake::sent('POST /clients')['address'])->toBe(['iso2' => 'FR'])
        ->and(WriteFake::sent('POST /clients')['vat_number'])->toBe('NC');
});

it('finds again by name alone a client without a postcode', function () {
    $draft = new ClientDraft(name: 'Exemple Test');
    WriteFake::start(['GET /clients' => [WriteFake::page([array_merge(WriteFake::fixture('client'), ['address' => ['postcode' => '', 'country' => ['iso2' => 'FR']]])])]]);

    expect(app(CreateClient::class)->run($draft))->toBeInstanceOf(AlreadyExists::class)
        ->and(WriteFake::times('POST /clients'))->toBe(0);
});

it('updates the mirror after creating', function () {
    WriteFake::start([
        'GET /clients' => [WriteFake::page()],
        'POST /clients' => [WriteFake::json('client', 201)],
    ]);

    app(CreateClient::class)->run(clientDraft());

    $row = DB::table('passorde_clients')->sole();

    expect((int) $row->evoliz_id)->toBe(601)->and($row->code)->toBe('EXEMPLETES')->and($row->country_iso2)->toBe('FR');
});

it('prefers the code it is given', function () {
    WriteFake::start([
        'GET /clients' => [WriteFake::page()],
        'POST /clients' => [WriteFake::json('client', 201)],
    ]);

    app(CreateClient::class)->run(clientDraft(['code' => 'MON-CODE']));

    expect(WriteFake::sent('POST /clients')['code'])->toBe('MON-CODE');
});

it('logs both the lookup and the create, bank details masked', function () {
    WriteFake::start([
        'GET /clients' => [WriteFake::page()],
        'POST /clients' => [WriteFake::json('client', 201)],
    ]);

    app(CreateClient::class)->run(clientDraft());

    $create = WriteFake::logged('client.create')[0];
    $response = json_decode($create->response, true);

    expect(WriteFake::logged('client.lookup'))->toHaveCount(1)
        ->and($create->status)->toBe('succeeded')
        ->and((int) $create->http_status)->toBe(201)
        ->and(json_decode($create->payload, true))->toBe(WriteFake::fixture('request-create-client'))
        ->and($response['clientid'])->toBe(601)
        ->and($response['bank_information']['iban'])->toBe('***')
        ->and($response['bank_information']['bank_name'])->toBe('Banque Fictive');
});

it('returns the same client when its code already exists, and keeps its contact in the mirror', function () {
    $client = WriteFake::fixture('client');
    WriteFake::start([
        'GET /clients' => [WriteFake::page([$client])],
        'GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact')])],
    ]);

    $result = app(CreateClient::class)->run(clientDraft(['code' => 'EXEMPLETES']));

    expect($result)->toBeInstanceOf(AlreadyExists::class)
        ->and($result->afterUncertainResponse)->toBeFalse()
        ->and($result->record->contact->id)->toBe(801)
        ->and(WriteFake::times('POST /clients'))->toBe(0)
        ->and((bool) DB::table('passorde_contacts')->sole()->is_active)->toBeTrue();
});

it('takes the next code when another client holds this one', function () {
    $other = array_merge(WriteFake::fixture('client'), ['name' => 'Autre Exemple']);
    WriteFake::start([
        'GET /clients' => [WriteFake::page([$other]), WriteFake::page()],
        'POST /clients' => [WriteFake::json('client', 201)],
    ]);

    expect(app(CreateClient::class)->run(clientDraft()))->toBeInstanceOf(Created::class)
        ->and(WriteFake::sent('POST /clients')['code'])->toBe('EXEMPLETE2');
});

it('does not take another code on a partial match of the listing', function () {
    $other = array_merge(WriteFake::fixture('client'), ['code' => 'EXEMPLETESXX', 'name' => 'Exemple Test']);
    WriteFake::start([
        'GET /clients' => [WriteFake::page([$other])],
        'POST /clients' => [WriteFake::json('client', 201)],
    ]);

    expect(app(CreateClient::class)->run(clientDraft()))->toBeInstanceOf(Created::class)
        ->and(WriteFake::sent('POST /clients')['code'])->toBe('EXEMPLETES');
});

it('gives the validation messages of the API back, field by field', function () {
    WriteFake::start([
        'GET /clients' => [WriteFake::page()],
        'POST /clients' => [WriteFake::json('error-validation', 400)],
    ]);

    $result = app(CreateClient::class)->run(clientDraft());

    expect($result)->toBeInstanceOf(Refused::class)
        ->and($result->succeeded())->toBeFalse()
        ->and($result->status)->toBe(400)
        ->and($result->fieldErrors)->toBe([
            'vat_number' => ['The vat number field is required.'],
            'address.postcode' => ['The postcode is invalid.'],
        ])
        ->and(WriteFake::times('POST /clients'))->toBe(1)
        ->and(WriteFake::logged('client.create')[0]->status)->toBe('failed');
});

it('finds the client after an uncertain answer instead of sending it again', function () {
    WriteFake::start([
        'GET /clients' => [WriteFake::page(), WriteFake::page([WriteFake::fixture('client')])],
        'GET /contacts-clients' => [WriteFake::page()],
        'POST /clients' => [WriteFake::serverError()],
    ]);

    $result = app(CreateClient::class)->run(clientDraft(['code' => 'EXEMPLETES']));

    expect($result)->toBeInstanceOf(AlreadyExists::class)
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and(WriteFake::times('POST /clients'))->toBe(1);
});

it('reports a failure in doubt when no attempt gets through and nothing is found', function () {
    WriteFake::start([
        'GET /clients' => array_fill(0, 6, WriteFake::page()),
        'POST /clients' => array_fill(0, 5, WriteFake::serverError()),
    ]);

    $result = app(CreateClient::class)->run(clientDraft());

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeTrue()
        ->and($result->status)->toBe(500)
        ->and(WriteFake::times('POST /clients'))->toBe(5);
});

it('still looks up with writes switched off, then refuses to write', function () {
    WriteFake::start(['GET /clients' => [WriteFake::page()]], writes: false);

    expect(fn () => app(CreateClient::class)->run(clientDraft()))->toThrow(WritesDisabledException::class)
        ->and(WriteFake::trail())->toBe(['GET /clients?code=EXEMPLETES&per_page=100&page=1'])
        ->and((int) WriteFake::logged('client.create')[0]->attempts)->toBe(0);
});

it('fails with nothing written when no code is free', function () {
    $taken = function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return WriteFake::page([array_merge(WriteFake::fixture('client'), ['code' => $query['code'], 'name' => 'Autre Exemple'])]);
    };
    WriteFake::start(['GET /clients' => array_fill(0, 99, $taken)]);

    $result = app(CreateClient::class)->run(clientDraft());

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeFalse()
        ->and(WriteFake::times('GET /clients'))->toBe(99)
        ->and(WriteFake::times('POST /clients'))->toBe(0)
        ->and(WriteFake::trail()[98])->toContain('code=EXEMPLET99');
});
