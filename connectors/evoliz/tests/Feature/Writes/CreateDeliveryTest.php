<?php

use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Passorde\Api\CacheLocks;
use Passorde\Api\Exceptions\WritesDisabledException;
use Passorde\Drafts\DeliveryDraft;
use Passorde\Drafts\DeliveryLineDraft;
use Passorde\Licence\Exceptions\LicenceExpiredException;
use Passorde\Licence\LicenceManager;
use Passorde\Sinks\Contracts\DeliverySink;
use Passorde\Tests\Support\FakeEvoliz;
use Passorde\Tests\Support\TestLicence;
use Passorde\Tests\Support\WriteFake;
use Passorde\Writes\CreateDelivery;
use Passorde\Writes\Results\AlreadyExists;
use Passorde\Writes\Results\Created;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;

const DELIVERY_LOOKUP = 'GET /deliveries?clientid=601&period=custom&date_min=2025-06-14&date_max=2026-07-21&per_page=100&page=1';

function deliveryDraft(array $overrides = []): DeliveryDraft
{
    return new DeliveryDraft(...array_merge([
        'clientId' => 601,
        'externalNumber' => 'CMD-2026-001',
        'documentDate' => '2026-06-20',
        'paytermId' => 7,
        'paytypeId' => 2,
        'comment' => 'Commande CMD-2026-001',
        'lines' => [
            new DeliveryLineDraft(3001, 2, '12.50'),
            new DeliveryLineDraft(3001, 1, 0, 'Fournitures &amp; matériel&nbsp;/ LOT 12 / offert'),
            new DeliveryLineDraft(3002, 1.5, 8, DeliveryLineDraft::withComment('Frais de port', "Livrer avant 10 h & sonner\nau portail")),
            new DeliveryLineDraft(3003, 4),
        ],
    ], $overrides));
}

it('looks up, then creates: dated ahead, articles on every line, recovery indemnity, the caller\'s reference', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page()],
        'POST /deliveries' => [WriteFake::json('delivery', 201)],
    ]);

    $result = app(CreateDelivery::class)->run(deliveryDraft());

    expect($result)->toBeInstanceOf(Created::class)
        ->and($result->succeeded())->toBeTrue()
        ->and($result->record->id)->toBe(9001)
        ->and($result->record->number)->toBe('BL-9001')
        ->and($result->record->externalNumber)->toBe('CMD-2026-001')
        ->and(WriteFake::trail())->toBe([DELIVERY_LOOKUP, 'POST /deliveries'])
        ->and(WriteFake::sent('POST /deliveries'))->toBe(WriteFake::fixture('request-create-delivery'));
});

it('sends whole numbers as integers on the wire', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page()],
        'POST /deliveries' => [WriteFake::json('delivery', 201)],
    ]);

    app(CreateDelivery::class)->run(deliveryDraft(['lines' => [new DeliveryLineDraft(3001, 2.0, '12.00')]]));

    expect(WriteFake::sent('POST /deliveries')['items'][0])->toBe(['type' => 'article', 'articleid' => 3001, 'quantity' => 2, 'unit_price' => 12]);
});

it('widens the lookup around both the document date and today', function (string $date, string $window) {
    WriteFake::start(['GET /deliveries' => [WriteFake::page([WriteFake::fixture('delivery')])]]);

    app(CreateDelivery::class)->run(deliveryDraft(['documentDate' => $date]));

    expect(WriteFake::trail()[0])->toContain($window);
})->with([
    'in the past' => ['2026-02-01', 'date_min=2025-01-31&date_max=2026-07-16'],
    'ahead' => ['2026-07-10', 'date_min=2025-06-14&date_max=2026-08-10'],
]);

it('updates the mirror, lines included', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page()],
        'POST /deliveries' => [WriteFake::json('delivery', 201)],
    ]);

    app(CreateDelivery::class)->run(deliveryDraft());

    $delivery = DB::table('passorde_deliveries')->sole();

    expect((int) $delivery->evoliz_id)->toBe(9001)
        ->and($delivery->external_number)->toBe('CMD-2026-001')
        ->and($delivery->document_date)->toStartWith('2026-06-20')
        ->and(DB::table('passorde_delivery_lines')->count())->toBe(2);
});

it('logs the request and the answer, bank details and the public link masked', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page()],
        'POST /deliveries' => [WriteFake::json('delivery', 201)],
    ]);

    app(CreateDelivery::class)->run(deliveryDraft());

    $row = WriteFake::logged('delivery.create')[0];
    $response = json_decode($row->response, true);

    expect(json_decode($row->payload, true))->toBe(WriteFake::fixture('request-create-delivery'))
        ->and($row->status)->toBe('succeeded')
        ->and($response['document_number'])->toBe('BL-9001')
        ->and($response['term']['bank']['iban'])->toBe('***')
        ->and($response['term']['bank']['bic'])->toBe('***')
        ->and($response['webdoc'])->toBe('***');
});

it('returns the existing delivery and creates nothing', function () {
    WriteFake::start(['GET /deliveries' => [WriteFake::page([WriteFake::fixture('delivery')])]]);

    $result = app(CreateDelivery::class)->run(deliveryDraft());

    expect($result)->toBeInstanceOf(AlreadyExists::class)
        ->and($result->afterUncertainResponse)->toBeFalse()
        ->and($result->record->id)->toBe(9001)
        ->and(WriteFake::times('POST /deliveries'))->toBe(0)
        ->and(DB::table('passorde_deliveries')->count())->toBe(1);
});

it('compares the number exactly: a longer one, another case or none is not this one', function () {
    $near = fn (?string $number): array => array_merge(WriteFake::fixture('delivery'), ['external_document_number' => $number]);
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page([$near('CMD-2026-0011'), $near('cmd-2026-001'), $near('X CMD-2026-001'), $near(null)])],
        'POST /deliveries' => [WriteFake::json('delivery', 201)],
    ]);

    expect(app(CreateDelivery::class)->run(deliveryDraft()))->toBeInstanceOf(Created::class);
});

it('finds a number that Evoliz stores encoded', function () {
    $encoded = array_merge(WriteFake::fixture('delivery'), ['external_document_number' => 'A&amp;B-1']);
    WriteFake::start(['GET /deliveries' => [WriteFake::page([$encoded])]]);

    expect(app(CreateDelivery::class)->run(deliveryDraft(['externalNumber' => 'A&B-1'])))->toBeInstanceOf(AlreadyExists::class);
});

it('reads every page before concluding, and ignores the same number on another client', function () {
    $foreign = array_merge(WriteFake::fixture('delivery'), ['client' => ['clientid' => 999]]);
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page([$foreign], lastPage: 2), WriteFake::page([WriteFake::fixture('delivery')], lastPage: 2)],
    ]);

    $result = app(CreateDelivery::class)->run(deliveryDraft());

    expect($result)->toBeInstanceOf(AlreadyExists::class)
        ->and($result->record->clientId)->toBe(601)
        ->and(WriteFake::times('GET /deliveries'))->toBe(2);
});

it('never uses the search filter', function () {
    WriteFake::start(['GET /deliveries' => [WriteFake::page([WriteFake::fixture('delivery')])]]);

    app(CreateDelivery::class)->run(deliveryDraft());

    expect(WriteFake::trail()[0])->not->toContain('search');
});

it('creates nothing when the lookup fails', function () {
    WriteFake::start(['GET /deliveries' => array_fill(0, 5, WriteFake::serverError())]);

    $result = app(CreateDelivery::class)->run(deliveryDraft());

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeFalse()
        ->and(WriteFake::times('POST /deliveries'))->toBe(0);
});

it('creates nothing when the list is too long to be read to its end', function () {
    $other = array_merge(WriteFake::fixture('delivery'), ['external_document_number' => 'AUTRE']);
    WriteFake::start(['GET /deliveries' => [WriteFake::page([$other], lastPage: 2)]]);
    config()->set('passorde-connect.evoliz.max_pages', 1);

    $result = app(CreateDelivery::class)->run(deliveryDraft());

    expect($result)->toBeInstanceOf(Failed::class)->and(WriteFake::times('POST /deliveries'))->toBe(0);
});

it('looks up after a network failure and returns what it finds, without sending again', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page(), WriteFake::page([WriteFake::fixture('delivery')])],
        'POST /deliveries' => [WriteFake::networkFailure()],
    ]);

    $result = app(CreateDelivery::class)->run(deliveryDraft());

    expect($result)->toBeInstanceOf(AlreadyExists::class)
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and(WriteFake::times('POST /deliveries'))->toBe(1)
        ->and(WriteFake::logged('delivery.create')[0]->status)->toBe('succeeded')
        ->and(WriteFake::logged('delivery.create')[0]->last_error)->toContain('Retrouvé')
        ->and(DB::table('passorde_deliveries')->count())->toBe(1);
});

it('looks up after a 500 too', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page(), WriteFake::page([WriteFake::fixture('delivery')])],
        'POST /deliveries' => [WriteFake::serverError()],
    ]);

    expect(app(CreateDelivery::class)->run(deliveryDraft()))->toBeInstanceOf(AlreadyExists::class)
        ->and(WriteFake::times('POST /deliveries'))->toBe(1);
});

it('sends again only once the lookup has said the delivery is not there', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page(), WriteFake::serverError(), WriteFake::page()],
        'POST /deliveries' => [WriteFake::networkFailure(), WriteFake::json('delivery', 201)],
    ]);

    expect(app(CreateDelivery::class)->run(deliveryDraft()))->toBeInstanceOf(Created::class)
        ->and(WriteFake::times('POST /deliveries'))->toBe(2)
        ->and(array_slice(array_map(fn (string $call): string => explode(' ', $call)[0], WriteFake::trail()), 0, 5))->toBe(['GET', 'POST', 'GET', 'GET', 'POST']);
});

it('stays in doubt, without sending again, when the lookup after a failure is refused', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page(), WriteFake::ok(['error' => 'Bad Request'], 400)],
        'POST /deliveries' => [WriteFake::networkFailure()],
    ]);

    $result = app(CreateDelivery::class)->run(deliveryDraft());

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeTrue()
        ->and(WriteFake::times('POST /deliveries'))->toBe(1);
});

it('looks once more when the attempts run out', function () {
    WriteFake::start([
        'GET /deliveries' => [...array_fill(0, 5, WriteFake::page()), WriteFake::page([WriteFake::fixture('delivery')])],
        'POST /deliveries' => array_fill(0, 5, WriteFake::serverError()),
    ]);

    $result = app(CreateDelivery::class)->run(deliveryDraft());

    expect($result)->toBeInstanceOf(AlreadyExists::class)
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and(WriteFake::logged('delivery.create')[0]->status)->toBe('succeeded')
        ->and(WriteFake::times('POST /deliveries'))->toBe(5);
});

it('is not in doubt after a rate limit: nothing was processed, and no lookup is needed', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page()],
        'POST /deliveries' => array_fill(0, 10, WriteFake::ok(['message' => 'Too Many'], 429)),
    ]);

    $result = app(CreateDelivery::class)->run(deliveryDraft());

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeFalse()
        ->and(WriteFake::times('GET /deliveries'))->toBe(1);
});

it('fails in doubt on a 2xx without an identifier', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page()],
        'POST /deliveries' => [WriteFake::ok(['status' => 'ok'], 201)],
    ]);

    $result = app(CreateDelivery::class)->run(deliveryDraft());

    expect($result)->toBeInstanceOf(Failed::class)->and($result->uncertain)->toBeTrue();
});

it('refuses a date too far ahead with the message of the API', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page()],
        'POST /deliveries' => [WriteFake::json('error-date', 400)],
    ]);

    $result = app(CreateDelivery::class)->run(deliveryDraft(['documentDate' => CarbonImmutable::now()->addDays(40)]));

    expect($result)->toBeInstanceOf(Refused::class)
        ->and($result->fieldErrors['documentdate'][0])->toContain('+ 30 days')
        ->and(WriteFake::times('POST /deliveries'))->toBe(1);
});

it('fails on credentials that are refused, rather than call it a refusal of the delivery', function () {
    WriteFake::start(['GET /deliveries' => [WriteFake::page()], 'POST /deliveries' => [WriteFake::ok(['error' => 'Unauthenticated'], 401), WriteFake::ok(['error' => 'Unauthenticated'], 401)]]);

    expect(app(CreateDelivery::class)->run(deliveryDraft()))->toBeInstanceOf(Failed::class);
});

it('keeps Created when the sink fails, with a warning', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page()],
        'POST /deliveries' => [WriteFake::json('delivery', 201)],
    ]);
    app()->bind(DeliverySink::class, fn () => new class implements DeliverySink
    {
        public function upsert(array $records, CarbonImmutable $syncedAt): int
        {
            throw new RuntimeException('Sink down');
        }

        public function removeMissing(CarbonImmutable $from, CarbonImmutable $to, array $seenIds, CarbonImmutable $syncedAt): int
        {
            return 0;
        }
    });
    Event::fake([MessageLogged::class]);

    expect(app(CreateDelivery::class)->run(deliveryDraft()))->toBeInstanceOf(Created::class);
    Event::assertDispatched(MessageLogged::class, fn (MessageLogged $log) => $log->level === 'warning' && str_contains($log->message, 'miroir'));
});

it('hands the record to a sink the application bound', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page()],
        'POST /deliveries' => [WriteFake::json('delivery', 201)],
    ]);
    $sink = new class implements DeliverySink
    {
        public array $received = [];

        public function upsert(array $records, CarbonImmutable $syncedAt): int
        {
            $this->received = array_merge($this->received, $records);

            return 1;
        }

        public function removeMissing(CarbonImmutable $from, CarbonImmutable $to, array $seenIds, CarbonImmutable $syncedAt): int
        {
            return 0;
        }
    };
    app()->instance(DeliverySink::class, $sink);

    app(CreateDelivery::class)->run(deliveryDraft());

    expect($sink->received[0]->id)->toBe(9001);
});

it('does not start while another worker holds the same delivery', function () {
    WriteFake::start([]);
    config()->set('passorde-connect.writes.lock_wait_seconds', 0);
    $held = CacheLocks::provider()->lock('passorde:write:delivery:601:'.hash('sha256', 'CMD-2026-001'), 60);
    $held->get();

    $result = app(CreateDelivery::class)->run(deliveryDraft());

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeTrue()
        ->and(WriteFake::trail())->toBe([]);

    $held->release();
});

it('releases its lock, so that the same call can run again', function () {
    WriteFake::start(['GET /deliveries' => [WriteFake::page([WriteFake::fixture('delivery')]), WriteFake::page([WriteFake::fixture('delivery')])]]);
    config()->set('passorde-connect.writes.lock_wait_seconds', 0);

    expect(app(CreateDelivery::class)->run(deliveryDraft()))->toBeInstanceOf(AlreadyExists::class)
        ->and(app(CreateDelivery::class)->run(deliveryDraft()))->toBeInstanceOf(AlreadyExists::class);
});

it('returns an existing delivery with writes off, and refuses to create one', function () {
    WriteFake::start(['GET /deliveries' => [WriteFake::page([WriteFake::fixture('delivery')]), WriteFake::page()]], writes: false);

    expect(app(CreateDelivery::class)->run(deliveryDraft()))->toBeInstanceOf(AlreadyExists::class);
    expect(fn () => app(CreateDelivery::class)->run(deliveryDraft()))->toThrow(WritesDisabledException::class);

    $row = WriteFake::logged('delivery.create')[0];

    expect(WriteFake::times('POST /deliveries'))->toBe(0)
        ->and($row->status)->toBe('failed')
        ->and((int) $row->attempts)->toBe(0);
});

it('calls nothing on an expired licence', function () {
    WriteFake::start([]);
    app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-01-01']));

    expect(fn () => app(CreateDelivery::class)->run(deliveryDraft()))->toThrow(LicenceExpiredException::class)
        ->and(WriteFake::trail())->toBe([])
        ->and(FakeEvoliz::logins())->toBe(0);
});

it('calls no invoicing path, whatever happens', function () {
    WriteFake::start([
        'GET /deliveries' => [WriteFake::page(), WriteFake::page()],
        'POST /deliveries' => [WriteFake::serverError(), WriteFake::json('delivery', 201)],
    ]);

    app(CreateDelivery::class)->run(deliveryDraft());

    foreach (WriteFake::trail() as $call) {
        expect($call)->not->toContain('invoice');
    }
});

it('offers no way to update, delete or invoice a delivery', function () {
    expect(get_class_methods(CreateDelivery::class))->toBe(['__construct', 'run']);
});
