<?php

use Illuminate\Support\Facades\DB;
use Passorde\Drafts\ArticleDraft;
use Passorde\Drafts\ClientDraft;
use Passorde\Drafts\ClientPatch;
use Passorde\Drafts\ContactDraft;
use Passorde\Tests\Support\WriteFake;
use Passorde\Writes\CreateArticle;
use Passorde\Writes\CreateClient;
use Passorde\Writes\Results\AlreadyExists;
use Passorde\Writes\Results\Created;
use Passorde\Writes\Results\Updated;
use Passorde\Writes\Support\Mirror;
use Passorde\Writes\UpdateClient;
use Passorde\Writes\UpsertContact;

it('is on by default, also for a config published before the key existed', function () {
    expect(Mirror::enabled())->toBeTrue();

    config()->set('passorde-connect.writes', ['lock_seconds' => 600]);
    expect(Mirror::enabled())->toBeTrue();

    config()->set('passorde-connect.writes.mirror', 'false');
    expect(Mirror::enabled())->toBeFalse();
});

it('updates a client without reading its contacts and without feeding the sinks, when off', function () {
    WriteFake::start(['PATCH /clients/601' => [WriteFake::ok(array_merge(WriteFake::fixture('client'), ['phone' => '00 00 00 00 03']))]]);
    config()->set('passorde-connect.writes.mirror', false);

    $result = app(UpdateClient::class)->run(601, new ClientPatch(phone: '00 00 00 00 03'));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->record->phone)->toBe('00 00 00 00 03')
        ->and($result->record->contact)->toBeNull()
        ->and(WriteFake::trail())->toBe(['PATCH /clients/601'])
        ->and(DB::table('passorde_clients')->count())->toBe(0);
});

it('writes a contact without reading the client back, when off', function () {
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact')])],
        'PATCH /contacts-clients/801' => [WriteFake::json('contact')],
    ]);
    config()->set('passorde-connect.writes.mirror', false);

    $result = app(UpsertContact::class)->run(601, new ContactDraft(email: 'camille@exemple.org', contactId: 801));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and(WriteFake::trail())->toBe(['GET /contacts-clients?clientid=601&per_page=100&page=1', 'PATCH /contacts-clients/801'])
        ->and(DB::table('passorde_contacts')->count())->toBe(0);
});

it('creates a client and finds an existing one without the contacts read, when off', function () {
    WriteFake::start([
        'GET /clients' => [WriteFake::page(), WriteFake::page([WriteFake::fixture('client')])],
        'POST /clients' => [WriteFake::json('client', 201)],
    ]);
    config()->set('passorde-connect.writes.mirror', false);
    $draft = new ClientDraft(name: 'Exemple Test', postcode: '00100');

    expect(app(CreateClient::class)->run($draft))->toBeInstanceOf(Created::class)
        ->and(app(CreateClient::class)->run($draft))->toBeInstanceOf(AlreadyExists::class)
        ->and(WriteFake::times('GET /contacts-clients'))->toBe(0)
        ->and(DB::table('passorde_clients')->count())->toBe(0);
});

it('creates an article without feeding the sinks, when off', function () {
    WriteFake::start([
        'GET /articles' => [WriteFake::page()],
        'POST /articles' => [WriteFake::json('article', 201)],
    ]);
    config()->set('passorde-connect.writes.mirror', false);

    expect(app(CreateArticle::class)->run(new ArticleDraft('ART-3001', 'Fournitures', '12.50', 5.5)))->toBeInstanceOf(Created::class)
        ->and(DB::table('passorde_articles')->count())->toBe(0);
});
