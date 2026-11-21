<?php

use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\LoginFailedException;
use Passorde\Licence\Exceptions\LicenceExpiredException;
use Passorde\Licence\LicenceManager;
use Passorde\Reads\ReadArticles;
use Passorde\Reads\ReadClients;
use Passorde\Reads\ReadDeliveries;
use Passorde\Reads\ReadPayterms;
use Passorde\Reads\ReadPaytypes;
use Passorde\Reads\ReadSaleClassifications;
use Passorde\Reads\ReadUsers;
use Passorde\Reads\ReadVatRates;
use Passorde\Records\ClientRecord;
use Passorde\Records\DeliveryRecord;
use Passorde\Tests\Support\FakeEvoliz;
use Passorde\Tests\Support\FakeListings;
use Passorde\Tests\Support\TestLicence;

function readerFake(array $routes): void
{
    FakeEvoliz::configure();
    FakeEvoliz::fake($routes);
}

function fixtureRoute(string $endpoint, ?string $fixture = null): array
{
    return [FakeEvoliz::SCOPE.'/'.$endpoint.'*' => Http::response(FakeEvoliz::fixture($fixture ?? $endpoint))];
}

it('maps an article: entities, nbsp, classification, supplier, negative stock', function () {
    readerFake(fixtureRoute('articles', 'catalogue'));

    [$first, $second] = iterator_to_array(app(ReadArticles::class)->all(), false);

    expect($first->id)->toBe(2001)
        ->and($first->reference)->toBe('CAT-001')
        ->and($first->designation)->toBe('Café moulu & torréfié Sachet 250 g')
        ->and($first->designationRaw)->toContain('&nbsp;')->toContain('<p>')
        ->and($first->unitPriceHt)->toBe('12.50')
        ->and($first->vatRate)->toBe('5.50')
        ->and($first->classificationId)->toBe(31)
        ->and($first->classificationCode)->toBe('707')
        ->and($first->supplierName)->toBe('Fournisseur Étoile')
        ->and($first->stockManaged)->toBeTrue()
        ->and($first->stockQty)->toBe('-3.25')
        ->and($first->enabled)->toBeTrue()
        ->and($first->raw['articleid'])->toBe(2001);

    expect($second->unitPriceHt)->toBe('80.00')
        ->and($second->classificationId)->toBeNull()
        ->and($second->supplierName)->toBeNull()
        ->and($second->stockQty)->toBeNull()
        ->and($second->stockManaged)->toBeFalse()
        ->and($second->enabled)->toBeFalse();
});

it('reads the country of a client in the shape Evoliz answers', function () {
    $client = fn (array $address) => ClientRecord::fromRow(['clientid' => 1, 'address' => $address]);

    expect($client(['country' => ['label' => 'France', 'iso2' => 'fr']])->countryIso2)->toBe('FR')
        ->and($client(['iso2' => 'FR'])->countryIso2)->toBe('FR')
        ->and($client([])->countryIso2)->toBeNull();
});

it('reads one article, wrapped in data or not, and null on 404', function () {
    readerFake([
        FakeEvoliz::SCOPE.'/articles/1' => Http::response(['articleid' => 1, 'designation' => 'A']),
        FakeEvoliz::SCOPE.'/articles/2' => Http::response(['data' => ['articleid' => 2, 'designation' => 'B']]),
        FakeEvoliz::SCOPE.'/articles/3' => Http::response(['error' => 'Not found'], 404),
        FakeEvoliz::SCOPE.'/articles/4' => Http::response(['error' => 'Bad'], 400),
    ]);

    $reader = app(ReadArticles::class);

    expect($reader->one(1)->designation)->toBe('A')
        ->and($reader->one(2)->designation)->toBe('B')
        ->and($reader->one(3))->toBeNull()
        ->and(fn () => $reader->one(4))->toThrow(ApiException::class);
});

it('follows pagination lazily with per_page=100', function () {
    readerFake([
        FakeEvoliz::SCOPE.'/articles?page=1&*' => Http::response(FakeEvoliz::fixture('articles-page-1')),
        FakeEvoliz::SCOPE.'/articles?page=2&*' => Http::response(FakeEvoliz::fixture('articles-page-2')),
    ]);

    $records = app(ReadArticles::class)->all();

    expect(FakeEvoliz::calls())->toBe([]);

    $first = $records->current();

    expect($first->id)->toBe(1001)
        ->and(FakeEvoliz::calls())->toHaveCount(1)
        ->and(FakeEvoliz::calls()[0]->url())->toContain('per_page=100');

    $ids = [];
    foreach ($records as $record) {
        $ids[] = $record->id;
    }

    expect($ids)->toHaveCount(3)
        ->and(FakeEvoliz::calls())->toHaveCount(2);
});

it('skips a row without identifier, with a warning', function () {
    readerFake([FakeEvoliz::SCOPE.'/paytypes*' => Http::response([
        'data' => [['label' => 'Sans id'], 'pas un tableau', ['paytypeid' => 4, 'label' => 'Espèces']],
        'meta' => ['last_page' => 1],
    ])]);
    Log::spy();

    $records = iterator_to_array(app(ReadPaytypes::class)->all(), false);

    expect($records)->toHaveCount(1)
        ->and($records[0]->id)->toBe(4);
    Log::shouldHaveReceived('warning')->twice();
});

it('attaches the primary contact: favourite first, enabled only, read once', function () {
    readerFake(array_merge(fixtureRoute('clients'), fixtureRoute('contacts-clients')));

    $clients = iterator_to_array(app(ReadClients::class)->all(), false);

    expect($clients)->toHaveCount(3);

    [$first, $second, $third] = $clients;

    expect($first->name)->toBe('Société Exemple')
        ->and($first->addressLine)->toBe("1 rue de l'Exemple, Bâtiment A")
        ->and($first->postcode)->toBe('00100')
        ->and($first->town)->toBe('Exempleville')
        ->and($first->countryIso2)->toBe('FR')
        ->and($first->phone)->toBe('00 00 00 00 01')
        ->and($first->mobile)->toBeNull()
        ->and($first->vatNumber)->toBe('FR00000000000')
        ->and($first->contact->id)->toBe(702)
        ->and($first->contact->fullName)->toBe('Camille Favori')
        ->and($first->contact->email)->toBe('camille.favori@exemple.org')
        ->and($first->contact->favourite)->toBeTrue();

    // The favourite of 502 is disabled, and the remaining contact's address is not valid.
    expect($second->contact->id)->toBe(705)
        ->and($second->contact->email)->toBeNull()
        ->and($second->addressLine)->toBe('2 place Fictive');

    expect($third->contact)->toBeNull()
        ->and($third->enabled)->toBeFalse()
        ->and($third->addressLine)->toBeNull();

    expect(FakeListings::callsTo('contacts-clients'))->toBe(1);
});

it('maps the reference lists', function () {
    readerFake(array_merge(
        fixtureRoute('paytypes'),
        fixtureRoute('payterms'),
        fixtureRoute('vat-rates'),
        fixtureRoute('sale-classifications'),
    ));

    expect(iterator_to_array(app(ReadPaytypes::class)->all(), false)[1]->label)->toBe('Chèque');
    expect(iterator_to_array(app(ReadPayterms::class)->all(), false)[0])
        ->id->toBe(11)
        ->label->toBe('30 jours');
    expect(iterator_to_array(app(ReadVatRates::class)->all(), false)[0])
        ->id->toBe(1)
        ->rate->toBe('20.00')
        ->label->toBe('TVA 20 %');
    expect(iterator_to_array(app(ReadSaleClassifications::class)->all(), false)[0])
        ->id->toBe(31)
        ->code->toBe('707')
        ->label->toBe('Ventes de marchandises');
});

it('reads a window with inclusive dates and never uses search', function () {
    readerFake(fixtureRoute('deliveries'));

    iterator_to_array(app(ReadDeliveries::class)->window(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-07-31')), false);

    $url = FakeEvoliz::calls()[0]->url();

    expect($url)->toContain('period=custom')
        ->toContain('date_min=2026-06-01')
        ->toContain('date_max=2026-07-31')
        ->not->toContain('search');
});

it('maps a delivery, its lines, free and article-less lines', function () {
    readerFake(fixtureRoute('deliveries'));

    [$delivery] = iterator_to_array(app(ReadDeliveries::class)->window(now(), now()), false);

    expect($delivery->id)->toBe(8001)
        ->and($delivery->number)->toBe('BL-8001')
        ->and($delivery->externalNumber)->toBe('CMD-77')
        ->and($delivery->clientId)->toBe(501)
        ->and($delivery->clientName)->toBe('Société Exemple')
        ->and($delivery->userId)->toBe(91)
        ->and($delivery->statusCode)->toBe(2)
        ->and($delivery->status)->toBe('create')
        ->and($delivery->invoiced)->toBeFalse()
        ->and($delivery->documentDate)->toBe('2026-06-12')
        ->and($delivery->totalHt)->toBe('130.00')
        ->and($delivery->totalVat)->toBe('7.15')
        ->and($delivery->totalTtc)->toBe('137.15')
        ->and($delivery->totalQuantity)->toBe('11.00')
        ->and($delivery->comment)->toBe('Livraison matin')
        ->and($delivery->lines)->toHaveCount(2);

    [$paid, $free] = $delivery->lines;

    expect($paid->position)->toBe(1)
        ->and($paid->articleId)->toBe(2001)
        ->and($paid->designation)->toBe('Café moulu')
        ->and($paid->quantity)->toBe('10.00')
        ->and($paid->unitPriceHt)->toBe('13.00')
        ->and($paid->vatRate)->toBe('5.50')
        ->and($paid->totalHt)->toBe('130.00')
        ->and($paid->isFree)->toBeFalse();

    expect($free->position)->toBe(2)
        ->and($free->articleId)->toBeNull()
        ->and($free->reference)->toBeNull()
        ->and($free->designation)->toBe('Geste commercial')
        ->and($free->vatRate)->toBeNull()
        ->and($free->isFree)->toBeTrue();
});

it('reads one delivery with its lines, mapped like the window, and null on 404', function () {
    $listed = FakeEvoliz::fixture('deliveries')['data'][0];
    readerFake([
        FakeEvoliz::SCOPE.'/deliveries/8001' => Http::response($listed),
        FakeEvoliz::SCOPE.'/deliveries/8002' => Http::response(['data' => $listed]),
        FakeEvoliz::SCOPE.'/deliveries/8003' => Http::response(['error' => 'Not found'], 404),
        FakeEvoliz::SCOPE.'/deliveries/8004' => Http::response(['error' => 'Bad'], 400),
    ]);

    $reader = app(ReadDeliveries::class);
    $one = $reader->one(8001);

    expect($one)->toEqual(DeliveryRecord::fromRow($listed))
        ->and($one->lines)->toHaveCount(2)
        ->and($reader->one(8002))->toEqual($one)
        ->and($reader->one(8003))->toBeNull()
        ->and(fn () => $reader->one(8004))->toThrow(ApiException::class)
        ->and(collect(FakeEvoliz::calls())->every(fn (Request $request) => $request->method() === 'GET'))->toBeTrue();
});

it('reads one delivery only behind the licence', function () {
    readerFake([FakeEvoliz::SCOPE.'/deliveries/8001' => Http::response(['deliveryid' => 8001])]);
    app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-01-01']));

    expect(fn () => app(ReadDeliveries::class)->one(8001))->toThrow(LicenceExpiredException::class)
        ->and(FakeEvoliz::calls())->toBe([]);
});

it('applies the invoiced rule: status 20 or 24, or an invoice date', function (array $row, bool $invoiced) {
    readerFake([FakeEvoliz::SCOPE.'/deliveries*' => Http::response([
        'data' => [array_merge(['deliveryid' => 1], $row)],
        'meta' => ['last_page' => 1],
    ])]);

    expect(iterator_to_array(app(ReadDeliveries::class)->window(now(), now()), false)[0]->invoiced)->toBe($invoiced);
})->with([
    'registered' => [['status_code' => 2, 'status_dates' => ['invoice' => null]], false],
    'invoiced' => [['status_code' => 20], true],
    'closed' => [['status_code' => 24], true],
    'invoice date only' => [['status_code' => 2, 'status_dates' => ['invoice' => '2026-06-15']], true],
    'no status at all' => [[], false],
]);

it('keeps a future-dated delivery as it is', function () {
    readerFake(fixtureRoute('deliveries'));

    $future = iterator_to_array(app(ReadDeliveries::class)->window(now(), now()), false)[2];

    expect($future->documentDate)->toBe('2026-07-10')
        ->and($future->lines)->toBe([]);
});

it('reads users', function () {
    readerFake(fixtureRoute('users'));

    expect(iterator_to_array(app(ReadUsers::class)->all(), false)[0])
        ->id->toBe(91)
        ->fullName->toBe('Morgan Exemple')
        ->email->toBe('morgan@exemple.org');
});

it('yields no user and warns when the scope is refused', function (int $status) {
    readerFake([FakeEvoliz::SCOPE.'/users*' => Http::response(['error' => 'Forbidden'], $status)]);
    Event::fake([MessageLogged::class]);

    expect(iterator_to_array(app(ReadUsers::class)->all(), false))->toBe([]);

    Event::assertDispatched(MessageLogged::class, fn (MessageLogged $log) => $log->level === 'warning' && str_contains($log->message, 'utilisateurs'));
})->with([401, 403]);

it('does not take a server error for a missing users scope', function () {
    readerFake([FakeEvoliz::SCOPE.'/users*' => Http::response(['error' => 'Oops'], 500)]);

    expect(fn () => iterator_to_array(app(ReadUsers::class)->all(), false))->toThrow(ApiException::class);
});

it('does not take refused credentials for a missing users scope', function () {
    FakeEvoliz::configure();
    Http::fake([FakeEvoliz::LOGIN => Http::response(FakeEvoliz::fixture('error-message'), 401)]);

    expect(fn () => iterator_to_array(app(ReadUsers::class)->all(), false))->toThrow(LoginFailedException::class);
});

it('does not pass a partial users list for a complete one', function () {
    readerFake([
        FakeEvoliz::SCOPE.'/users?page=1&*' => Http::response(['data' => [['userid' => 1]], 'meta' => ['last_page' => 2]]),
        FakeEvoliz::SCOPE.'/users?page=2&*' => Http::response(['error' => 'Forbidden'], 403),
    ]);

    expect(fn () => iterator_to_array(app(ReadUsers::class)->all(), false))->toThrow(ApiException::class);
});

it('sends only GET requests', function () {
    readerFake(array_merge(fixtureRoute('articles', 'catalogue'), fixtureRoute('deliveries')));

    iterator_to_array(app(ReadArticles::class)->all(), false);
    iterator_to_array(app(ReadDeliveries::class)->window(now(), now()), false);

    expect(array_unique(array_map(fn (Request $request) => $request->method(), FakeEvoliz::calls())))->toBe(['GET']);
});
