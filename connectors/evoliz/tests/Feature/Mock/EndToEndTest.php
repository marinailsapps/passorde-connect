<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\Exceptions\ForbiddenPathException;
use Passorde\Api\Exceptions\WritesDisabledException;
use Passorde\Drafts\ArticleDraft;
use Passorde\Drafts\ClientDraft;
use Passorde\Drafts\ClientPatch;
use Passorde\Drafts\ContactDraft;
use Passorde\Drafts\DeliveryDraft;
use Passorde\Drafts\DeliveryLineDraft;
use Passorde\Licence\LicenceManager;
use Passorde\Mock\Seed;
use Passorde\Reads\ReadPayterms;
use Passorde\Reads\ReadSaleClassifications;
use Passorde\Reads\ReadVatRates;
use Passorde\Tests\Support\MockServer;
use Passorde\Tests\Support\TestLicence;
use Passorde\Writes\AdjustStock;
use Passorde\Writes\CreateArticle;
use Passorde\Writes\CreateClient;
use Passorde\Writes\CreateDelivery;
use Passorde\Writes\Results\AlreadyExists;
use Passorde\Writes\Results\Created;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Results\StockAdjusted;
use Passorde\Writes\Results\Updated;
use Passorde\Writes\UpdateClient;
use Passorde\Writes\UpsertContact;

uses()->group('mock');

beforeEach(function () {
    $this->mock = MockServer::prepare();
    Sleep::fake();
});

function mockClient(): ClientDraft
{
    return new ClientDraft(
        name: 'Maison Fictive & Fils',
        addressLine: '4 impasse Imaginaire',
        postcode: '00400',
        town: 'Exempleville',
        phone: '00 00 00 00 40',
    );
}

function mockDelivery(string $external = 'CMD-MOCK-001', int $clientId = 5001, string $date = '2026-06-20'): DeliveryDraft
{
    return new DeliveryDraft(
        clientId: $clientId,
        externalNumber: $external,
        documentDate: $date,
        paytermId: 37,
        lines: [
            new DeliveryLineDraft(articleId: 3001, quantity: 2),
            new DeliveryLineDraft(articleId: 3002, quantity: 1, unitPriceHt: 0, designationOverride: 'Offert'),
        ],
        paytypeId: 42,
        comment: 'Commande '.$external,
    );
}

it('drives the whole public interface end to end', function () {
    $this->artisan('passorde:ping')->assertExitCode(0);
    $this->artisan('passorde:sync all')->assertExitCode(0);
    $this->artisan('passorde:sync deliveries --since=2025-12-01 --until=2026-07-31')->assertExitCode(0);

    expect(DB::table('passorde_clients')->count())->toBe(12)
        ->and(DB::table('passorde_contacts')->count())->toBe(11)
        ->and(DB::table('passorde_articles')->count())->toBe(36)
        ->and(DB::table('passorde_paytypes')->count())->toBe(2)
        ->and(DB::table('passorde_users')->count())->toBe(2)
        ->and(DB::table('passorde_deliveries')->count())->toBe(24);

    $client = app(CreateClient::class)->run(mockClient());
    expect($client)->toBeInstanceOf(Created::class);
    $repeat = app(CreateClient::class)->run(mockClient());
    expect($repeat)->toBeInstanceOf(AlreadyExists::class)
        ->and($repeat->record->id)->toBe($client->record->id);

    $contact = app(UpsertContact::class)->run($client->record->id, new ContactDraft(lastName: 'Fictif', email: 'nouveau@mock.invalid'));
    expect($contact)->toBeInstanceOf(Created::class)->and($contact->record->favourite)->toBeTrue();
    expect(app(UpsertContact::class)->run($client->record->id, new ContactDraft(lastName: 'Fictif-Modifié', email: 'nouveau@mock.invalid')))
        ->toBeInstanceOf(Updated::class);

    expect(app(UpdateClient::class)->run($client->record->id, new ClientPatch(phone: '00 00 00 00 41')))
        ->toBeInstanceOf(Updated::class);

    $draft = new ArticleDraft('MOCK-1', 'Article & essai', '12.50', 5.5, 301, true, 7);
    $article = app(CreateArticle::class)->run($draft);
    expect($article)->toBeInstanceOf(Created::class)->and($article->record->stockQty)->toBe('7.00');
    expect(app(CreateArticle::class)->run($draft))->toBeInstanceOf(AlreadyExists::class);

    $delivery = app(CreateDelivery::class)->run(mockDelivery(clientId: $client->record->id));
    expect($delivery)->toBeInstanceOf(Created::class)
        ->and($delivery->record->number)->toStartWith('BL-MOCK-')
        ->and($delivery->record->externalNumber)->toBe('CMD-MOCK-001');
    $again = app(CreateDelivery::class)->run(mockDelivery(clientId: $client->record->id));
    expect($again)->toBeInstanceOf(AlreadyExists::class)
        ->and($again->record->id)->toBe($delivery->record->id)
        ->and($this->mock->times('POST', '/deliveries'))->toBe(1);

    $stock = app(AdjustStock::class)->run($article->record->id, -2);
    expect($stock)->toBeInstanceOf(StockAdjusted::class)
        ->and([$stock->before, $stock->written, $stock->after, $stock->confirmed])->toBe(['7.00', '5.00', '5.00', true]);

    $this->artisan('passorde:sync all')->assertExitCode(0);
    $this->artisan('passorde:status')
        ->expectsOutputToContain('clients : ')
        ->expectsOutputToContain('deliveries : ')
        ->expectsOutputToContain('Valide')
        ->assertExitCode(0);

    expect(DB::table('passorde_clients')->count())->toBe(13)
        ->and(DB::table('passorde_articles')->count())->toBe(37)
        ->and(DB::table('passorde_deliveries')->where('evoliz_id', $delivery->record->id)->value('number'))->toBe($delivery->record->number)
        ->and($this->mock->state()['forbidden_hits'])->toBe([]);
});

it('changes nothing in the mirror when a sync finds nothing new', function () {
    $this->artisan('passorde:sync clients')->assertExitCode(0);
    $before = DB::table('passorde_clients')->orderBy('id')->pluck('row_hash')->all();

    $this->artisan('passorde:sync clients')->assertExitCode(0);

    expect(DB::table('passorde_runs')->where('kind', 'clients')->orderByDesc('id')->value('rows_changed'))->toBe(0)
        ->and(DB::table('passorde_clients')->orderBy('id')->pluck('row_hash')->all())->toBe($before);
});

it('reads deliveries by period, with their items inline', function () {
    $this->artisan('passorde:sync deliveries')->assertExitCode(0);

    // Seven days back and 31 ahead of the mock's date: one on that date and one eight days after it.
    expect(DB::table('passorde_deliveries')->count())->toBe(2)
        ->and(DB::table('passorde_delivery_lines')->count())->toBeGreaterThan(1);

    $this->artisan('passorde:sync deliveries --since=2025-12-01 --until=2026-07-31')->assertExitCode(0);

    expect(DB::table('passorde_deliveries')->count())->toBe(24)
        ->and(DB::table('passorde_deliveries')->where('invoiced', true)->count())->toBeGreaterThan(0)
        ->and(DB::table('passorde_runs')->where('kind', 'deliveries')->count())->toBeGreaterThan(2);
});

it('reads the reference data it does not mirror', function () {
    expect(iterator_to_array(app(ReadVatRates::class)->all(), false))->toHaveCount(3)
        ->and(iterator_to_array(app(ReadPayterms::class)->all(), false))->toHaveCount(2)
        ->and(iterator_to_array(app(ReadSaleClassifications::class)->all(), false))->toHaveCount(2);
});

it('follows real pagination', function () {
    $pages = iterator_to_array(app(EvolizApi::class)->paginate('/articles', [], 10), false);

    expect($pages)->toHaveCount(4)
        ->and(count($pages[0]))->toBe(10)
        ->and(count($pages[3]))->toBe(6)
        ->and($this->mock->times('GET', '/articles'))->toBe(4);

    $body = app(EvolizApi::class)->get('/articles', ['per_page' => 10, 'page' => 2]);

    expect($body['meta'])->toMatchArray(['current_page' => 2, 'from' => 11, 'last_page' => 4, 'per_page' => 10, 'to' => 20, 'total' => 36])
        ->and($body['links']['prev'])->toContain('page=1')
        ->and($body['links']['next'])->toContain('page=3');
});

it('refuses a per_page out of range, and the search filter on deliveries', function () {
    $token = $this->mock->token();

    expect(Http::withToken($token)->get($this->mock->url('/articles'), ['per_page' => 101])->status())->toBe(400)
        ->and(Http::withToken($token)->get($this->mock->url('/deliveries'), ['search' => 'x'])->json('error'))->toBe('Unexpected filter');
});

it('finds a delivery by its external number through the period listing', function () {
    $created = app(CreateDelivery::class)->run(mockDelivery());

    $rows = app(EvolizApi::class)->get('/deliveries', ['clientid' => 5001, 'period' => 'custom', 'date_min' => '2026-06-20', 'date_max' => '2026-06-20'])['data'];

    expect(array_column($rows, 'external_document_number'))->toContain('CMD-MOCK-001')
        ->and(array_column($rows, 'deliveryid'))->toContain($created->record->id)
        ->and($rows[0]['items'])->toHaveCount(2);
});

it('moves the stock of an article on a delivery, into a read that AdjustStock then sees', function () {
    $api = app(EvolizApi::class);
    $start = $api->get('/articles/3001')['stocked_quantity'];

    app(CreateDelivery::class)->run(mockDelivery());
    expect($api->get('/articles/3001')['stocked_quantity'])->toEqual($start - 2);

    $adjusted = app(AdjustStock::class)->run(3001, 10);

    expect($adjusted->confirmed)->toBeTrue()
        ->and((float) $adjusted->after)->toEqual($start + 8);
});

it('keeps two deliveries with the same number on two clients, as the external number is per client', function () {
    $first = app(CreateDelivery::class)->run(mockDelivery(clientId: 5001));
    $second = app(CreateDelivery::class)->run(mockDelivery(clientId: 5002));

    expect($first)->toBeInstanceOf(Created::class)
        ->and($second)->toBeInstanceOf(Created::class)
        ->and($second->record->id)->not->toBe($first->record->id);
});

it('recovers without a duplicate when a delivery was stored but answered 500', function () {
    $this->mock->switch(['delivery_post_500' => true]);

    $result = app(CreateDelivery::class)->run(mockDelivery());

    expect($result)->toBeInstanceOf(AlreadyExists::class)
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and($this->mock->times('POST', '/deliveries'))->toBe(1)
        ->and($this->mock->state()['counts']['deliveries'])->toBe(25);
});

it('takes a client PATCH as applied after a 500 that applied it', function () {
    $this->mock->switch(['client_patch_500' => true]);

    $result = app(UpdateClient::class)->run(5001, new ClientPatch(phone: '00 00 00 00 99'));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and($result->record->phone)->toBe('00 00 00 00 99')
        ->and($this->mock->times('PATCH', '/clients/5001'))->toBe(1);
});

it('gives back a validation refusal with its fields', function () {
    $result = app(CreateDelivery::class)->run(mockDelivery(date: '2026-08-01'));

    expect($result)->toBeInstanceOf(Refused::class)
        ->and($result->status)->toBe(400)
        ->and($result->fieldErrors)->toHaveKey('documentdate')
        ->and($this->mock->state()['counts']['deliveries'])->toBe(24);
});

it('requires vat_number on POST /clients', function () {
    $response = app(EvolizApi::class)->send('POST', '/clients', ['json' => [
        'name' => 'Sans TVA', 'type' => 'Professionnel',
        'address' => ['addr' => '1 rue Fictive', 'postcode' => '00100', 'town' => 'Exempleville', 'iso2' => 'FR'],
    ]]);

    expect($response->status())->toBe(400)
        ->and($response->json('message.vat_number'))->toBe(['The vat number field is required.']);
});

it('takes the next code when another client holds the one wanted', function () {
    $result = app(CreateClient::class)->run(new ClientDraft(name: 'Autre', addressLine: '1 rue Fictive', postcode: '00900', town: 'Exempleville', code: 'FICTIF01'));

    expect($result)->toBeInstanceOf(Created::class)
        ->and($result->record->code)->not->toBe('FICTIF01');
});

it('never lets the favourite contact lose its email', function () {
    $result = app(UpsertContact::class)->run(5001, new ContactDraft(lastName: 'Alpha', email: '', contactId: 6501));

    expect($result)->toBeInstanceOf(Refused::class)
        ->and($this->mock->times('PATCH', '/contacts-clients/6501'))->toBe(0);
});

it('refuses every write with a read-only key, and keeps reading', function () {
    $this->mock->switch(['read_only' => true]);

    $results = [
        app(CreateClient::class)->run(mockClient()),
        app(UpdateClient::class)->run(5001, new ClientPatch(phone: '00 00 00 00 77')),
        app(UpsertContact::class)->run(5001, new ContactDraft(lastName: 'Alpha', email: 'autre@mock.invalid')),
        app(CreateArticle::class)->run(new ArticleDraft('MOCK-RO', 'Essai', 1, 20)),
        app(CreateDelivery::class)->run(mockDelivery()),
        app(AdjustStock::class)->run(3001, 1),
    ];

    foreach ($results as $result) {
        expect($result)->toBeInstanceOf(Refused::class)->and($result->status)->toBe(403);
    }

    expect($this->mock->state()['counts'])->toBe(['clients' => 12, 'contacts' => 12, 'articles' => 36, 'deliveries' => 24]);

    $this->artisan('passorde:ping')->assertExitCode(0);
    $this->artisan('passorde:sync clients')->assertExitCode(0);
});

it('answers 403 or 405 on every invoicing and delivery-mutation path', function (string $method, string $route, int $status) {
    $response = Http::withToken($this->mock->token())->send($method, $this->mock->url($route));

    expect($response->status())->toBe($status)
        ->and($response->json('error'))->toBe($status === 403 ? 'Forbidden' : 'Method Not Allowed')
        ->and($this->mock->state()['forbidden_hits'])->toHaveCount(1)
        ->and($response->header('X-RateLimit-Remaining') === '')->toBe($status === 405);
})->with([
    ['GET', '/invoices', 403],
    ['POST', '/invoices', 403],
    ['PATCH', '/invoices/1', 403],
    ['POST', '/deliveries/8001/invoice', 403],
    ['POST', '/quotes/1/invoice', 403],
    ['POST', '/sale-orders/1/invoice', 403],
    ['POST', '/progress-invoice', 403],
    ['POST', '/credits', 403],
    ['POST', '/recoveries', 403],
    ['DELETE', '/deliveries/8001', 403],
    ['PATCH', '/deliveries/8001', 405],
    ['PUT', '/deliveries/8001', 405],
    ['POST', '/deliveries/8001', 405],
    ['POST', '/deliveries/8001/send', 405],
    ['GET', '/deliveries/8001/lines', 405],
]);

it('stops those paths in Passorde before anything reaches the mock', function (string $method, string $route) {
    expect(fn () => app(EvolizApi::class)->send($method, $route))->toThrow(ForbiddenPathException::class);
    expect($this->mock->state()['requests'])->toBe([]);
})->with([
    ['GET', '/invoices'],
    ['POST', '/invoices'],
    ['POST', '/deliveries/8001/invoice'],
    ['POST', '/credits'],
    ['PATCH', '/deliveries/8001'],
    ['DELETE', '/deliveries/8001'],
    ['POST', '/deliveries/8001/send'],
]);

it('sends no write while writes are off', function () {
    config()->set('passorde-connect.writes_enabled', false);

    expect(fn () => app(CreateDelivery::class)->run(mockDelivery()))->toThrow(WritesDisabledException::class)
        ->and($this->mock->times('POST', '/deliveries'))->toBe(0);
});

it('makes no call at all when the licence blocks it', function (array $fields) {
    file_put_contents($this->licencePath, TestLicence::signed($fields));

    $this->artisan('passorde:ping')->assertExitCode(2);
    $this->artisan('passorde:status')->assertExitCode(2);

    expect($this->mock->state()['requests'])->toBe([]);
})->with([
    'expired past its grace' => [['expires_at' => '2026-01-01']],
    'version_max below the package' => [['version_max' => '0.9']],
]);

it('keeps working through the grace period', function () {
    app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-06-10']));

    $this->artisan('passorde:status')->assertExitCode(1);
    $this->artisan('passorde:ping')->assertExitCode(0);
});

it('counts the rate limit down in its headers', function () {
    $api = app(EvolizApi::class);

    $first = $api->send('GET', '/paytypes');
    $second = $api->send('GET', '/paytypes');

    expect($first->header('X-RateLimit-Limit'))->toBe('100')
        ->and((int) $second->header('X-RateLimit-Remaining'))->toBe((int) $first->header('X-RateLimit-Remaining') - 1);
});

it('pauses when few calls remain', function () {
    Log::spy();
    $this->mock->switch(['rate_limit_remaining' => 5]);

    $this->artisan('passorde:ping')->expectsOutputToContain('Appels restants : 5')->assertExitCode(0);

    // Once after the login and once after the call: the mock answers 5 remaining to both.
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 60, 2);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'rate limit nearly exhausted'));
});

it('waits on a 429 and reports the failure when it lasts', function () {
    app(EvolizApi::class)->get('/paytypes');
    $this->mock->switch(['rate_limit_remaining' => 0]);

    $this->artisan('passorde:ping')->assertExitCode(1);

    expect($this->mock->times('GET', '/vat-rates'))->toBe(2);
    Sleep::assertSlept(fn ($duration) => $duration->totalSeconds >= 1, 1);
});

it('reconnects once its token is no longer accepted', function () {
    $this->artisan('passorde:ping')->assertExitCode(0);
    $this->mock->reset();
    $this->artisan('passorde:ping')->assertExitCode(0);

    expect($this->mock->logins())->toBe(1)
        ->and($this->mock->times('GET', '/vat-rates'))->toBe(2);
});

it('reports refused keys', function () {
    config()->set('passorde-connect.evoliz.secret_key', 'not-the-mock-value');

    $this->artisan('passorde:ping')->assertExitCode(1);

    expect($this->mock->state()['requests'][0]['status'])->toBe(401);
});

it('refuses another company', function () {
    config()->set('passorde-connect.evoliz.company_id', 2000);

    $this->artisan('passorde:ping')->assertExitCode(1);
});

it('logs each call of a verb with its secrets masked', function () {
    app(CreateDelivery::class)->run(mockDelivery());

    $row = DB::table('passorde_requests')->where('kind', 'delivery.create')->first();

    expect($row->status)->toBe('succeeded')
        ->and($row->http_status)->toBe(201)
        ->and($row->response)->toContain('BL-MOCK-')
        ->and($row->response)->not->toContain('FR00 0000')
        ->and($row->response)->not->toContain('FICTFRPP')
        ->and($row->response)->not->toContain('mock.invalid/webdoc')
        ->and(DB::table('passorde_requests')->where('response', 'like', '%'.MockServer::SECRET.'%')->exists())->toBeFalse();
});

it('forgets everything on a reset', function () {
    app(CreateClient::class)->run(mockClient());
    $this->mock->switch(['read_only' => true]);

    $this->mock->reset();

    $state = $this->mock->state();

    expect($state['counts']['clients'])->toBe(12)
        ->and($state['switches']['read_only'])->toBeFalse()
        ->and($state['requests'])->toBe([])
        ->and($state['today'])->toBe(MockServer::TODAY);
});

it('rejects an unknown switch', function () {
    $answer = Http::post($this->mock->base.'/__mock/switches', ['nope' => true]);

    expect($answer->status())->toBe(400)->and($answer->json('known'))->toContain('read_only');
});

it('never keeps a login body', function () {
    Http::post($this->mock->base.'/api/login', ['public_key' => 'a-mistaken-key', 'secret_key' => 'a-mistaken-value']);

    expect(json_encode($this->mock->state()))->not->toContain('a-mistaken');
});

it('builds its data from the date alone, all of it fictional', function () {
    $mock = dirname(__DIR__, 3).'/mock/src/';
    require_once $mock.'Rows.php';
    require_once $mock.'Seed.php';

    $seed = Seed::build('2026-06-15');
    $text = (string) json_encode($seed, JSON_UNESCAPED_UNICODE);
    $dates = array_column($seed['deliveries'], 'documentdate');

    expect(Seed::build('2026-06-15'))->toBe($seed)
        ->and(Seed::build('2026-06-16'))->not->toBe($seed)
        ->and($seed['clients'])->toHaveCount(12)
        ->and($seed['articles'])->toHaveCount(36)
        ->and($seed['deliveries'])->toHaveCount(24)
        ->and($seed['paytypes'])->toHaveCount(2)
        ->and($seed['users'])->toHaveCount(2)
        ->and(Carbon::parse(min($dates))->diffInMonths(Carbon::parse(max($dates))))->toBeGreaterThan(5)
        ->and($text)->not->toMatch('/@(?!mock\.invalid)/u');

    // Every text the mock builds (names, contacts, addresses, designations, comments, labels) is one of its own
    // invented values, marked as such, or a code, number, date or address of its own shape.
    $allowed = [
        '/\b(Fictif|Fictive|Fictifs|Fictives|Fiction)\b|^(Exempleville|Fictiveville|Modeleville)$/iu',
        '/^('.implode('|', (new ReflectionClassConstant(Seed::class, 'CONTACT_NAMES'))->getValue()).'|Contact|Secondaire|Utilisateur|Un|Deux)$/u',
        '/^(Professionnel|article|product|create|invoice|France|Virement|Chèque|À réception|\d+ jours|Bâtiment A)$/u',
        '/^Référence EXT-\d{4}$/u',
        '/^[A-Z0-9 &-]*$/u',
        '/^\d{4}-\d{2}-\d{2}$/u',
        '/^[a-z.]+@mock\.invalid$|^https:\/\/mock\.invalid\//u',
    ];
    $values = [];
    array_walk_recursive($seed, function (mixed $value) use (&$values) {
        if (is_string($value)) {
            $values[] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    });

    expect($values)->toContain('Comptoir Fictif Alpha', 'Référence EXT-0002', 'Alpha', 'Exempleville');

    foreach (array_unique($values) as $value) {
        expect(collect($allowed)->contains(fn (string $pattern) => preg_match($pattern, $value) === 1))
            ->toBeTrue("Not one of the mock's invented values: {$value}");
    }
});
