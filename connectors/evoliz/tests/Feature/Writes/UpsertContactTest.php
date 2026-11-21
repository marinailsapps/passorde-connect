<?php

use Illuminate\Support\Facades\DB;
use Passorde\Drafts\ContactDraft;
use Passorde\Drafts\InvalidDraftException;
use Passorde\Tests\Support\WriteFake;
use Passorde\Writes\Results\Created;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Results\Updated;
use Passorde\Writes\UpsertContact;

function otherContact(array $overrides = []): array
{
    return array_merge(WriteFake::fixture('contact'), ['contactid' => 802, 'lastname' => 'Second', 'firstname' => null, 'email' => 'second@exemple.org', 'favorite' => false], $overrides);
}

it('creates the contact of a client that has none, favourite since it has an email', function () {
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page(), WriteFake::page([WriteFake::fixture('contact')])],
        'POST /contacts-clients' => [WriteFake::json('contact', 201)],
        'GET /clients/601' => [WriteFake::json('client')],
    ]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft('Exemple', 'Camille', 'camille@exemple.org'));

    expect($result)->toBeInstanceOf(Created::class)
        ->and($result->record->id)->toBe(801)
        ->and(WriteFake::sent('POST /contacts-clients'))->toBe(WriteFake::fixture('request-create-contact'))
        ->and(DB::table('passorde_contacts')->sole()->email)->toBe('camille@exemple.org');
});

it('creates a contact without an email and without the favourite flag', function () {
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page(), WriteFake::page()],
        'POST /contacts-clients' => [WriteFake::json('contact', 201)],
        'GET /clients/601' => [WriteFake::json('client')],
    ]);

    app(UpsertContact::class)->run(601, new ContactDraft('Exemple'));

    expect(WriteFake::sent('POST /contacts-clients'))->toBe(['clientid' => 601, 'lastname' => 'Exemple']);
});

it('refuses to make a contact favourite without an email', function () {
    WriteFake::start(['GET /contacts-clients' => [WriteFake::page()]]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft('Exemple', favourite: true));

    expect($result)->toBeInstanceOf(Refused::class)
        ->and($result->status)->toBeNull()
        ->and($result->fieldErrors)->toHaveKey('email')
        ->and(WriteFake::times('POST /contacts-clients'))->toBe(0);
});

it('replaces the email of the favourite contact, the whole name in one field', function () {
    $after = array_merge(WriteFake::fixture('contact'), ['firstname' => null, 'email' => 'nouvelle@exemple.org']);
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page([otherContact(), WriteFake::fixture('contact')]), WriteFake::page([otherContact(), $after])],
        'PATCH /contacts-clients/801' => [WriteFake::ok($after)],
        'GET /clients/601' => [WriteFake::json('client')],
    ]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft('Exemple', '', 'nouvelle@exemple.org'));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->record->email)->toBe('nouvelle@exemple.org')
        ->and(WriteFake::sent('PATCH /contacts-clients/801'))->toBe(WriteFake::fixture('request-update-contact'))
        ->and(DB::table('passorde_contacts')->sole()->email)->toBe('nouvelle@exemple.org');
});

it('never clears the email of a favourite contact, and sends nothing', function () {
    WriteFake::start(['GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact')])]]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft('Exemple', email: ''));

    expect($result)->toBeInstanceOf(Refused::class)
        ->and($result->status)->toBeNull()
        ->and($result->fieldErrors)->toHaveKey('email')
        ->and(WriteFake::times('PATCH /contacts-clients/801'))->toBe(0);
});

it('clears the email of another contact when told to', function () {
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact'), otherContact()]), WriteFake::page([WriteFake::fixture('contact')])],
        'PATCH /contacts-clients/802' => [WriteFake::ok(otherContact(['email' => null]))],
        'GET /clients/601' => [WriteFake::json('client')],
    ]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft('Second', email: '', contactId: 802));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and(WriteFake::sent('PATCH /contacts-clients/802'))->toBe(['lastname' => 'Second', 'email' => null]);
});

it('picks the contact with this email before the favourite one', function () {
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact'), otherContact()]), WriteFake::page()],
        'PATCH /contacts-clients/802' => [WriteFake::ok(otherContact())],
        'GET /clients/601' => [WriteFake::json('client')],
    ]);

    expect(app(UpsertContact::class)->run(601, new ContactDraft('Second', email: 'SECOND@exemple.org')))->toBeInstanceOf(Updated::class);
});

it('ignores a contact of another client in the answer, and refuses an id it does not know', function () {
    $foreign = otherContact(['client' => ['clientid' => 999]]);
    WriteFake::start(['GET /contacts-clients' => [WriteFake::page([$foreign])]]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft('Second', contactId: 802));

    expect($result)->toBeInstanceOf(Refused::class)->and($result->fieldErrors)->toHaveKey('contactId');
});

it('gives back the validation messages of a 400', function () {
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact')])],
        'PATCH /contacts-clients/801' => [WriteFake::json('error-email', 400)],
    ]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft('Exemple', email: 'pas-un-email'));

    expect($result)->toBeInstanceOf(Refused::class)
        ->and($result->status)->toBe(400)
        ->and($result->fieldErrors)->toBe(['email' => ['The email address must be a valid email address.']])
        ->and($result->message)->toContain('email :');
});

it('sends a change again after a 500 that did not apply it', function () {
    $after = array_merge(WriteFake::fixture('contact'), ['firstname' => 'Alex']);
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact')]), WriteFake::page([$after])],
        'PATCH /contacts-clients/801' => [WriteFake::serverError(), WriteFake::ok($after)],
        'GET /contacts-clients/801' => [WriteFake::json('contact')],
        'GET /clients/601' => [WriteFake::json('client')],
    ]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft('Exemple', 'Alex'));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->afterUncertainResponse)->toBeFalse()
        ->and(WriteFake::times('PATCH /contacts-clients/801'))->toBe(2);
});

it('takes a 500 for success when the contact read back holds the change', function () {
    $after = array_merge(WriteFake::fixture('contact'), ['firstname' => 'Alex']);
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact')]), WriteFake::page([$after])],
        'PATCH /contacts-clients/801' => [WriteFake::serverError()],
        'GET /contacts-clients/801' => [WriteFake::ok($after)],
        'GET /clients/601' => [WriteFake::json('client')],
    ]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft('Exemple', 'Alex'));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and($result->record->firstName)->toBe('Alex')
        ->and(WriteFake::times('PATCH /contacts-clients/801'))->toBe(1);
});

it('finds a created contact after an uncertain answer instead of creating it twice', function () {
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page(), WriteFake::page([WriteFake::fixture('contact')]), WriteFake::page([WriteFake::fixture('contact')])],
        'POST /contacts-clients' => [WriteFake::networkFailure()],
        'GET /clients/601' => [WriteFake::json('client')],
    ]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft('Exemple', 'Camille', 'Camille@exemple.org'));

    expect($result)->toBeInstanceOf(Created::class)
        ->and(WriteFake::times('POST /contacts-clients'))->toBe(1);
});

it('keeps the update a success when the mirror cannot be refreshed', function () {
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact')])],
        'PATCH /contacts-clients/801' => [WriteFake::json('contact')],
        'GET /clients/601' => [WriteFake::serverError()],
    ]);

    expect(app(UpsertContact::class)->run(601, new ContactDraft('Exemple')))->toBeInstanceOf(Updated::class);
});

it('fails with nothing written when the contacts cannot be listed', function () {
    WriteFake::start(['GET /contacts-clients' => array_fill(0, 5, WriteFake::serverError())]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft('Exemple'));

    expect($result)->toBeInstanceOf(Failed::class)->and($result->uncertain)->toBeFalse();
});

it('corrects an email alone, sending no lastname', function () {
    $after = array_merge(WriteFake::fixture('contact'), ['email' => 'corrigee@exemple.org']);
    WriteFake::start([
        'GET /contacts-clients' => [WriteFake::page([WriteFake::fixture('contact')]), WriteFake::page([$after])],
        'PATCH /contacts-clients/801' => [WriteFake::ok($after)],
        'GET /clients/601' => [WriteFake::json('client')],
    ]);

    $result = app(UpsertContact::class)->run(601, new ContactDraft(email: 'corrigee@exemple.org', contactId: 801));

    expect($result)->toBeInstanceOf(Updated::class)
        ->and($result->record->email)->toBe('corrigee@exemple.org')
        ->and(WriteFake::sent('PATCH /contacts-clients/801'))->toBe(['email' => 'corrigee@exemple.org']);
});

it('refuses to create a contact without a lastname, sending nothing', function () {
    WriteFake::start(['GET /contacts-clients' => [WriteFake::page()]]);

    expect(fn () => app(UpsertContact::class)->run(601, new ContactDraft(email: 'camille@exemple.org')))
        ->toThrow(InvalidDraftException::class, 'lastName');

    expect(WriteFake::times('POST /contacts-clients'))->toBe(0);
});
