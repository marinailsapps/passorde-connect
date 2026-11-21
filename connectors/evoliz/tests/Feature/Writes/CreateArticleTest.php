<?php

use Illuminate\Support\Facades\DB;
use Passorde\Drafts\ArticleDraft;
use Passorde\Records\ArticleRecord;
use Passorde\Tests\Support\WriteFake;
use Passorde\Writes\CreateArticle;
use Passorde\Writes\Results\AlreadyExists;
use Passorde\Writes\Results\Created;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;

function stockedArticle(): ArticleDraft
{
    return new ArticleDraft(
        reference: 'ART-3001',
        designation: 'Fournitures & matériel <lot>',
        unitPriceHt: '12.50',
        vatRate: 5.5,
        classificationId: 41,
        stockManaged: true,
        stockQty: 7,
    );
}

it('creates a stock-managed article with its starting stock, its text escaped', function () {
    WriteFake::start([
        'GET /articles' => [WriteFake::page()],
        'POST /articles' => [WriteFake::json('article', 201)],
    ]);

    $result = app(CreateArticle::class)->run(stockedArticle());

    expect($result)->toBeInstanceOf(Created::class)
        ->and($result->record)->toBeInstanceOf(ArticleRecord::class)
        ->and($result->record->stockQty)->toBe('7.00')
        ->and(WriteFake::trail())->toBe(['GET /articles?reference=ART-3001&per_page=100&page=1', 'POST /articles'])
        ->and(WriteFake::sent('POST /articles'))->toBe(WriteFake::fixture('request-create-article-stocked'));

    $row = DB::table('passorde_articles')->sole();

    expect((int) $row->evoliz_id)->toBe(3001)->and($row->stock_qty)->toEqual('7.00');
});

it('sends no stock for an article without stock management', function () {
    WriteFake::start([
        'GET /articles' => [WriteFake::page()],
        'POST /articles' => [WriteFake::json('article', 201)],
    ]);

    app(CreateArticle::class)->run(new ArticleDraft('PORT', 'Frais de port', 8, 20, nature: 'service'));

    expect(WriteFake::sent('POST /articles'))->toBe(WriteFake::fixture('request-create-article-unstocked'));
});

it('starts a stock-managed article at zero by default', function () {
    expect((new ArticleDraft('R', 'D', 1, 5.5, stockManaged: true))->toRequest()['stocked_quantity'])->toBe(0);
});

it('returns the article whose reference matches exactly, encoded or in other capitals', function () {
    $article = array_merge(WriteFake::fixture('article'), ['reference' => 'art&#45;3001']);
    WriteFake::start(['GET /articles' => [WriteFake::page([$article])]]);

    $result = app(CreateArticle::class)->run(stockedArticle());

    expect($result)->toBeInstanceOf(AlreadyExists::class)
        ->and($result->afterUncertainResponse)->toBeFalse()
        ->and(WriteFake::times('POST /articles'))->toBe(0)
        ->and(DB::table('passorde_articles')->count())->toBe(1);
});

it('does not take a longer reference for this one', function () {
    $other = array_merge(WriteFake::fixture('article'), ['reference' => 'ART-3001-B']);
    WriteFake::start([
        'GET /articles' => [WriteFake::page([$other])],
        'POST /articles' => [WriteFake::json('article', 201)],
    ]);

    expect(app(CreateArticle::class)->run(stockedArticle()))->toBeInstanceOf(Created::class);
});

it('gives back the validation messages', function () {
    WriteFake::start([
        'GET /articles' => [WriteFake::page()],
        'POST /articles' => [WriteFake::json('error-validation', 400)],
    ]);

    expect(app(CreateArticle::class)->run(stockedArticle()))->toBeInstanceOf(Refused::class);
});

it('finds the article after an uncertain answer', function () {
    WriteFake::start([
        'GET /articles' => [WriteFake::page(), WriteFake::page([WriteFake::fixture('article')])],
        'POST /articles' => [WriteFake::serverError()],
    ]);

    $result = app(CreateArticle::class)->run(stockedArticle());

    expect($result)->toBeInstanceOf(AlreadyExists::class)
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and(WriteFake::times('POST /articles'))->toBe(1);
});

it('fails in doubt on a 2xx without an identifier', function () {
    WriteFake::start([
        'GET /articles' => [WriteFake::page()],
        'POST /articles' => [WriteFake::ok(['status' => 'ok'], 201)],
    ]);

    $result = app(CreateArticle::class)->run(stockedArticle());

    expect($result)->toBeInstanceOf(Failed::class)->and($result->uncertain)->toBeTrue();
});
