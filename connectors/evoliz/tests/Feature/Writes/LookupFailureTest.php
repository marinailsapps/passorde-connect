<?php

use Passorde\Drafts\ArticleDraft;
use Passorde\Drafts\ClientDraft;
use Passorde\Drafts\ContactDraft;
use Passorde\Drafts\DeliveryDraft;
use Passorde\Drafts\DeliveryLineDraft;
use Passorde\Tests\Support\WriteFake;
use Passorde\Writes\AdjustStock;
use Passorde\Writes\CreateArticle;
use Passorde\Writes\CreateClient;
use Passorde\Writes\CreateDelivery;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\UpsertContact;

/**
 * The five verbs that read before writing, each with the listing it reads first and the write it would send.
 *
 * @return array<string, array{string, string, Closure(): mixed}>
 */
function verbsThatLookUp(): array
{
    return [
        'CreateClient' => ['GET /clients', 'POST /clients', fn () => app(CreateClient::class)->run(new ClientDraft(name: 'Exemple Test', postcode: '00100'))],
        'CreateArticle' => ['GET /articles', 'POST /articles', fn () => app(CreateArticle::class)->run(new ArticleDraft('ART-3001', 'Fournitures', '12.50', 5.5))],
        'CreateDelivery' => ['GET /deliveries', 'POST /deliveries', fn () => app(CreateDelivery::class)->run(new DeliveryDraft(601, 'CMD-2026-001', '2026-06-20', 7, [new DeliveryLineDraft(3001, 1)]))],
        'UpsertContact' => ['GET /contacts-clients', 'POST /contacts-clients', fn () => app(UpsertContact::class)->run(601, new ContactDraft('Exemple', email: 'camille@exemple.org'))],
        'AdjustStock' => ['GET /articles/3001', 'PATCH /articles/3001', fn () => app(AdjustStock::class)->run(3001, -1)],
    ];
}

it('returns Failed, certain, with the reason from Evoliz, when the first read cannot be made', function (string $read, string $write, Closure $run) {
    WriteFake::start([$read => array_fill(0, 5, WriteFake::serverError())]);

    $result = $run();

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeFalse()
        ->and($result->status)->toBe(500)
        ->and($result->message)->toContain('Server Error')
        ->and(WriteFake::times($write))->toBe(0);
})->with(verbsThatLookUp());

it('returns Failed, certain, after a network failure on the first read', function (string $read, string $write, Closure $run) {
    WriteFake::start([$read => array_fill(0, 5, WriteFake::networkFailure())]);

    $result = $run();

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeFalse()
        ->and($result->status)->toBeNull()
        ->and($result->message)->not->toBe('')
        ->and(WriteFake::times($write))->toBe(0);
})->with(verbsThatLookUp());

it('returns Refused, sending nothing, when Evoliz refuses the first read', function (string $read, string $write, Closure $run) {
    WriteFake::start([$read => [WriteFake::ok(['error' => 'Bad Request Error', 'message' => 'Unexpected filter'], 400)]]);

    $result = $run();

    expect($result)->toBeInstanceOf(Refused::class)
        ->and($result->status)->toBe(400)
        ->and(WriteFake::times($write))->toBe(0);
})->with(verbsThatLookUp());

it('returns Failed, certain, when a listing is too long to be read to its end', function (string $read, string $write, Closure $run) {
    WriteFake::start([$read => [WriteFake::page([['id' => 0]], lastPage: 2)]]);
    config()->set('passorde-connect.evoliz.max_pages', 1);

    $result = $run();

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeFalse()
        ->and($result->message)->not->toBe('')
        ->and(WriteFake::times($write))->toBe(0);
})->with(array_diff_key(verbsThatLookUp(), ['AdjustStock' => true]));
