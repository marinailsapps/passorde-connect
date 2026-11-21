<?php

use Carbon\CarbonImmutable;
use Passorde\Drafts\ArticleDraft;
use Passorde\Drafts\ClientDraft;
use Passorde\Drafts\ClientPatch;
use Passorde\Drafts\ContactDraft;
use Passorde\Drafts\DeliveryDraft;
use Passorde\Drafts\DeliveryLineDraft;
use Passorde\Drafts\InvalidDraftException;

function draftLine(): DeliveryLineDraft
{
    return new DeliveryLineDraft(articleId: 3001, quantity: 1);
}

it('refuses a draft that could not make a valid request', function (Closure $build, string $field) {
    try {
        $build();
    } catch (InvalidDraftException $exception) {
        expect($exception->fieldErrors)->toHaveKey($field);

        return;
    }

    throw new RuntimeException('Draft accepted.');
})->with([
    'client without a name' => [fn () => new ClientDraft(' ', 'a', 'b', 'c'), 'name'],
    'client with an empty VAT number' => [fn () => new ClientDraft('n', 'a', 'b', 'c', vatNumber: ''), 'vatNumber'],
    'client with a bad country' => [fn () => new ClientDraft('n', 'a', 'b', 'c', countryIso2: 'France'), 'countryIso2'],
    'client with a bad type' => [fn () => new ClientDraft('n', 'a', 'b', 'c', type: 'Autre'), 'type'],
    'empty patch' => [fn () => new ClientPatch, 'patch'],
    'patch emptying the name' => [fn () => new ClientPatch(name: ''), 'name'],
    'patch emptying the VAT number' => [fn () => new ClientPatch(vatNumber: ''), 'vatNumber'],
    'patch emptying the postcode' => [fn () => new ClientPatch(postcode: ' '), 'postcode'],
    'patch emptying the town' => [fn () => new ClientPatch(town: ''), 'town'],
    'patch with a bad country' => [fn () => new ClientPatch(countryIso2: 'F'), 'countryIso2'],
    'contact without a name' => [fn () => new ContactDraft(''), 'lastName'],
    'contact with nothing to write' => [fn () => new ContactDraft(contactId: 801), 'contact'],
    'contact with a bad id' => [fn () => new ContactDraft('n', contactId: 0), 'contactId'],
    'article without a reference' => [fn () => new ArticleDraft('', 'd', 1, 5.5), 'reference'],
    'article without a designation' => [fn () => new ArticleDraft('r', '', 1, 5.5), 'designation'],
    'article with a bad price' => [fn () => new ArticleDraft('r', 'd', 'douze', 5.5), 'unitPriceHt'],
    'article with a bad rate' => [fn () => new ArticleDraft('r', 'd', 1, 'x'), 'vatRate'],
    'article with stock but no stock management' => [fn () => new ArticleDraft('r', 'd', 1, 5.5, stockQty: 3), 'stockQty'],
    'article with a bad nature' => [fn () => new ArticleDraft('r', 'd', 1, 5.5, nature: 'x'), 'nature'],
    'line without an article' => [fn () => new DeliveryLineDraft(0, 1), 'articleId'],
    'line with no quantity' => [fn () => new DeliveryLineDraft(1, 0), 'quantity'],
    'line with a bad quantity' => [fn () => new DeliveryLineDraft(1, 'deux'), 'quantity'],
    'line with a bad price' => [fn () => new DeliveryLineDraft(1, 1, 'x'), 'unitPriceHt'],
    'delivery without a client' => [fn () => new DeliveryDraft(0, 'n', '2026-06-20', 7, [draftLine()]), 'clientId'],
    'delivery without a reference' => [fn () => new DeliveryDraft(1, ' ', '2026-06-20', 7, [draftLine()]), 'externalNumber'],
    'delivery without a payment term' => [fn () => new DeliveryDraft(1, 'n', '2026-06-20', 0, [draftLine()]), 'paytermId'],
    'delivery with a bad payment type' => [fn () => new DeliveryDraft(1, 'n', '2026-06-20', 7, [draftLine()], paytypeId: 0), 'paytypeId'],
    'delivery without lines' => [fn () => new DeliveryDraft(1, 'n', '2026-06-20', 7, []), 'lines'],
    'delivery with a bad date' => [fn () => new DeliveryDraft(1, 'n', '20/06/2026', 7, [draftLine()]), 'documentDate'],
    'delivery with an impossible date' => [fn () => new DeliveryDraft(1, 'n', '2026-02-31', 7, [draftLine()]), 'documentDate'],
]);

it('takes a document date as a date object or as text', function () {
    expect((new DeliveryDraft(1, 'n', CarbonImmutable::parse('2026-07-01 23:00:00'), 7, [draftLine()]))->documentDate)->toBe('2026-07-01')
        ->and((new DeliveryDraft(1, 'n', '2026-07-01', 7, [draftLine()]))->documentDate)->toBe('2026-07-01');
});

it('sends whole numbers as integers and others as they are', function () {
    $item = (new DeliveryLineDraft(3001, '2.00', '12.50'))->toRequest();

    expect($item['quantity'])->toBe(2)->and($item['unit_price'])->toBe(12.5);
});

it('keeps a comment on its own line under the designation, as text', function () {
    expect(DeliveryLineDraft::withComment('Article&nbsp;/ LOT 12', "a & <b>\nsuite"))
        ->toBe("Article&nbsp;/ LOT 12<br />\na &amp; &lt;b&gt;<br />\nsuite");
});

it('sends a recovery indemnity by default, and what the caller asks otherwise', function () {
    expect((new DeliveryDraft(1, 'n', '2026-07-01', 7, [draftLine()]))->toRequest()['term'])->toBe(['paytermid' => 7, 'recovery_indemnity' => true])
        ->and((new DeliveryDraft(1, 'n', '2026-07-01', 7, [draftLine()], recoveryIndemnity: false))->toRequest()['term'])->toBe(['paytermid' => 7, 'recovery_indemnity' => false]);
});

it('leaves out of a new client the street, postcode and town it does not have, keeping the address block and NC', function () {
    expect((new ClientDraft('Société Fictive'))->toRequest('SOCIETEFIC'))->toBe([
        'type' => 'Professionnel',
        'name' => 'Société Fictive',
        'code' => 'SOCIETEFIC',
        'vat_number' => 'NC',
        'address' => ['iso2' => 'FR'],
    ])
        ->and((new ClientDraft('Société Fictive', ' ', '00100', 'EXEMPLEVILLE'))->toRequest('SOCIETEFIC')['address'])
        ->toBe(['postcode' => '00100', 'town' => 'EXEMPLEVILLE', 'iso2' => 'FR'])
        ->and((new ClientDraft('Société Fictive', addressLine: '1 rue Fictive', town: 'EXEMPLEVILLE'))->toRequest('SOCIETEFIC')['address'])
        ->toBe(['addr' => '1 rue Fictive', 'town' => 'EXEMPLEVILLE', 'iso2' => 'FR']);
});

it('builds a patch of the given fields only', function () {
    expect((new ClientPatch(phone: '01'))->toRequest())->toBe(['phone' => '01'])
        ->and((new ClientPatch(mobile: ''))->toRequest())->toBe(['mobile' => null])
        ->and((new ClientPatch(town: 'Fictiveville'))->toRequest())->toBe(['address' => ['town' => 'Fictiveville']])
        ->and((new ClientPatch(addressLine2: ''))->toRequest())->toBe(['address' => ['addr2' => null]])
        ->and((new ClientPatch(addressLine: '1 rue', addressLine2: 'B'))->toRequest())->toBe(['address' => ['addr' => '1 rue', 'addr2' => 'B', 'addr3' => null]]);
});

it('clears the whole street with an empty address line, as addr: null', function () {
    expect((new ClientPatch(addressLine: ''))->toRequest())->toBe(['address' => ['addr' => null, 'addr2' => null, 'addr3' => null]])
        ->and((new ClientPatch(addressLine: ' ', town: 'EXEMPLEVILLE'))->toRequest())->toBe(['address' => ['addr' => null, 'addr2' => null, 'addr3' => null, 'town' => 'EXEMPLEVILLE']]);
});

it('leaves the lastname of an existing contact alone when the draft has none, and needs it to create one', function () {
    expect((new ContactDraft(email: 'camille@exemple.org'))->toUpdateRequest())->toBe(['email' => 'camille@exemple.org'])
        ->and((new ContactDraft('Exemple'))->toUpdateRequest())->toBe(['lastname' => 'Exemple'])
        ->and(fn () => (new ContactDraft(email: 'camille@exemple.org'))->toCreateRequest(601))->toThrow(InvalidDraftException::class, 'lastName');
});
