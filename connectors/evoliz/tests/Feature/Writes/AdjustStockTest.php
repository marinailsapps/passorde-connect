<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Passorde\Api\Exceptions\WritesDisabledException;
use Passorde\Api\RequestContext;
use Passorde\Drafts\InvalidDraftException;
use Passorde\Drafts\StockExpectation;
use Passorde\Tests\Support\WriteFake;
use Passorde\Writes\AdjustStock;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Results\StockAdjusted;

function articleWithStock(mixed $quantity, array $overrides = []): array
{
    return array_merge(WriteFake::fixture('article'), ['stocked_quantity' => $quantity], $overrides);
}

it('reads, writes the value computed from it, and reads again, every call logged', function () {
    WriteFake::start([
        'GET /articles/3001' => [WriteFake::ok(articleWithStock(7)), WriteFake::ok(articleWithStock(6))],
        'PATCH /articles/3001' => [WriteFake::ok(articleWithStock(6))],
    ]);

    $result = app(AdjustStock::class)->run(3001, -1);

    expect($result)->toBeInstanceOf(StockAdjusted::class)
        ->and($result->succeeded())->toBeTrue()
        ->and($result->before)->toBe('7.00')
        ->and($result->written)->toBe('6.00')
        ->and($result->after)->toBe('6.00')
        ->and($result->confirmed)->toBeTrue()
        ->and($result->record->stockQty)->toBe('6.00')
        ->and(WriteFake::trail())->toBe(['GET /articles/3001', 'PATCH /articles/3001', 'GET /articles/3001'])
        ->and(WriteFake::sent('PATCH /articles/3001'))->toBe(['stocked_quantity' => 6])
        ->and(array_column(WriteFake::logged(), 'kind'))->toBe(['stock.read', 'stock.adjust', 'stock.verify'])
        ->and(json_decode(WriteFake::logged('stock.adjust')[0]->payload, true))->toBe(['stocked_quantity' => 6])
        ->and(json_decode(WriteFake::logged('stock.verify')[0]->response, true)['stocked_quantity'])->toBe(6);

    expect(DB::table('passorde_articles')->sole()->stock_qty)->toEqual('6.00');
});

it('handles decimals, a string delta and a stock going below zero', function () {
    WriteFake::start([
        'GET /articles/3001' => [WriteFake::ok(articleWithStock(1.25)), WriteFake::ok(articleWithStock(-0.5))],
        'PATCH /articles/3001' => [WriteFake::ok(articleWithStock(-0.5))],
    ]);

    $result = app(AdjustStock::class)->run(3001, '-1.75');

    expect($result->written)->toBe('-0.50')
        ->and(WriteFake::sent('PATCH /articles/3001'))->toBe(['stocked_quantity' => -0.5]);
});

it('counts an empty stock as zero', function () {
    WriteFake::start([
        'GET /articles/3001' => [WriteFake::ok(articleWithStock(null)), WriteFake::ok(articleWithStock(3))],
        'PATCH /articles/3001' => [WriteFake::ok(articleWithStock(3))],
    ]);

    expect(app(AdjustStock::class)->run(3001, 3)->before)->toBe('0.00')
        ->and(WriteFake::sent('PATCH /articles/3001'))->toBe(['stocked_quantity' => 3]);
});

it('refuses an article without stock management and writes nothing', function () {
    WriteFake::start(['GET /articles/3001' => [WriteFake::ok(articleWithStock(null, ['stock_management' => false]))]]);

    $result = app(AdjustStock::class)->run(3001, 1);

    expect($result)->toBeInstanceOf(Refused::class)
        ->and($result->status)->toBeNull()
        ->and(WriteFake::times('PATCH /articles/3001'))->toBe(0);
});

it('refuses an unknown article', function () {
    WriteFake::start(['GET /articles/3001' => [WriteFake::ok(['error' => 'Not found'], 404)]]);

    expect(app(AdjustStock::class)->run(3001, 1))->toBeInstanceOf(Refused::class);
});

it('refuses an adjustment of nothing', function () {
    expect(fn () => app(AdjustStock::class)->run(3001, 0))->toThrow(InvalidDraftException::class);
});

it('reports a stock that something else moved, with the fresh value in the mirror', function () {
    WriteFake::start([
        'GET /articles/3001' => [WriteFake::ok(articleWithStock(7)), WriteFake::ok(articleWithStock(4))],
        'PATCH /articles/3001' => [WriteFake::ok(articleWithStock(6))],
    ]);
    Event::fake([MessageLogged::class]);

    $result = app(AdjustStock::class)->run(3001, -1);

    expect($result)->toBeInstanceOf(StockAdjusted::class)
        ->and($result->written)->toBe('6.00')
        ->and($result->after)->toBe('4.00')
        ->and($result->confirmed)->toBeFalse()
        ->and(DB::table('passorde_articles')->sole()->stock_qty)->toEqual('4.00');
    Event::assertDispatched(MessageLogged::class, fn (MessageLogged $log) => $log->level === 'warning' && str_contains($log->message, 'stock diffère'));
});

it('does not claim to have confirmed what it could not read back', function () {
    WriteFake::start([
        'GET /articles/3001' => [WriteFake::ok(articleWithStock(7)), ...array_fill(0, 5, WriteFake::serverError())],
        'PATCH /articles/3001' => [WriteFake::ok(articleWithStock(6))],
    ]);

    $result = app(AdjustStock::class)->run(3001, -1);

    expect($result)->toBeInstanceOf(StockAdjusted::class)
        ->and($result->after)->toBeNull()
        ->and($result->confirmed)->toBeFalse()
        ->and($result->record->stockQty)->toBe('6.00');
});

it('takes a 500 for success when the stock read back is what it wrote, and does not apply it twice', function () {
    WriteFake::start([
        'GET /articles/3001' => [WriteFake::ok(articleWithStock(7)), WriteFake::ok(articleWithStock(6)), WriteFake::ok(articleWithStock(6))],
        'PATCH /articles/3001' => [WriteFake::serverError()],
    ]);

    $result = app(AdjustStock::class)->run(3001, -1);

    expect($result)->toBeInstanceOf(StockAdjusted::class)
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and($result->confirmed)->toBeTrue()
        ->and(WriteFake::times('PATCH /articles/3001'))->toBe(1);
});

it('writes the same value again when the stock read back has not moved', function () {
    WriteFake::start([
        'GET /articles/3001' => [WriteFake::ok(articleWithStock(7)), WriteFake::ok(articleWithStock(7)), WriteFake::ok(articleWithStock(6))],
        'PATCH /articles/3001' => [WriteFake::networkFailure(), WriteFake::ok(articleWithStock(6))],
    ]);

    $result = app(AdjustStock::class)->run(3001, -1);

    expect($result->confirmed)->toBeTrue()
        ->and(WriteFake::times('PATCH /articles/3001'))->toBe(2)
        ->and(WriteFake::sent('PATCH /articles/3001', 1))->toBe(['stocked_quantity' => 6]);
});

it('stops in doubt rather than overwrite a stock that moved during the retry', function () {
    WriteFake::start([
        'GET /articles/3001' => [WriteFake::ok(articleWithStock(7)), WriteFake::ok(articleWithStock(5))],
        'PATCH /articles/3001' => [WriteFake::serverError()],
    ]);

    $result = app(AdjustStock::class)->run(3001, -1);

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeTrue()
        ->and($result->message)->toContain('5.00')
        ->and(WriteFake::times('PATCH /articles/3001'))->toBe(1);
});

it('reads with writes off, then refuses to write', function () {
    WriteFake::start(['GET /articles/3001' => [WriteFake::ok(articleWithStock(7))]], writes: false);

    expect(fn () => app(AdjustStock::class)->run(3001, -1))->toThrow(WritesDisabledException::class)
        ->and(WriteFake::times('PATCH /articles/3001'))->toBe(0);
});

it('fails with nothing written when the first read fails', function () {
    WriteFake::start(['GET /articles/3001' => array_fill(0, 5, WriteFake::networkFailure())]);

    $result = app(AdjustStock::class)->run(3001, -1);

    expect($result)->toBeInstanceOf(Failed::class)->and($result->uncertain)->toBeFalse();
});

it('reports what it attempted when a timed-out PATCH ends in doubt', function () {
    WriteFake::start([
        'GET /articles/3001' => array_fill(0, 6, WriteFake::ok(articleWithStock(7))),
        'PATCH /articles/3001' => array_fill(0, 5, WriteFake::networkFailure()),
    ]);

    $result = app(AdjustStock::class)->run(3001, -1);

    expect($result)->toBeInstanceOf(Failed::class)
        ->and($result->uncertain)->toBeTrue()
        ->and($result->before)->toBe('7.00')
        ->and($result->written)->toBe('6.00');
});

it('recognises on the retry a timed-out PATCH that went through, and sends no second PATCH', function () {
    WriteFake::start([
        'GET /articles/3001' => [...array_fill(0, 6, WriteFake::ok(articleWithStock(7))), WriteFake::ok(articleWithStock(6))],
        'PATCH /articles/3001' => array_fill(0, 5, WriteFake::networkFailure()),
    ]);

    $failed = app(AdjustStock::class)->run(3001, -1);
    $patches = WriteFake::times('PATCH /articles/3001');

    // Evoliz applied the last PATCH after all, before the retry reads the stock.
    $result = app(AdjustStock::class)->run(3001, -1, new StockExpectation($failed->before, $failed->written));

    expect($result)->toBeInstanceOf(StockAdjusted::class)
        ->and($result->confirmed)->toBeTrue()
        ->and($result->afterUncertainResponse)->toBeTrue()
        ->and($result->before)->toBe('7.00')
        ->and($result->written)->toBe('6.00')
        ->and($result->after)->toBe('6.00')
        ->and(WriteFake::times('PATCH /articles/3001'))->toBe($patches)
        ->and(DB::table('passorde_articles')->sole()->stock_qty)->toEqual('6.00');
});

it('adjusts normally from the fresh stock when someone else moved it meanwhile', function () {
    WriteFake::start([
        'GET /articles/3001' => [WriteFake::ok(articleWithStock(4)), WriteFake::ok(articleWithStock(3))],
        'PATCH /articles/3001' => [WriteFake::ok(articleWithStock(3))],
    ]);

    $result = app(AdjustStock::class)->run(3001, -1, new StockExpectation('7.00', '6.00'));

    expect($result)->toBeInstanceOf(StockAdjusted::class)
        ->and($result->before)->toBe('4.00')
        ->and($result->written)->toBe('3.00')
        ->and($result->confirmed)->toBeTrue()
        ->and($result->afterUncertainResponse)->toBeFalse()
        ->and(WriteFake::sent('PATCH /articles/3001'))->toBe(['stocked_quantity' => 3]);
});

it('adjusts again when the stock still shows the value read before the attempt in doubt', function () {
    WriteFake::start([
        'GET /articles/3001' => [WriteFake::ok(articleWithStock(7)), WriteFake::ok(articleWithStock(6))],
        'PATCH /articles/3001' => [WriteFake::ok(articleWithStock(6))],
    ]);

    $result = app(AdjustStock::class)->run(3001, -1, new StockExpectation('7.00', '6.00'));

    expect($result->written)->toBe('6.00')
        ->and($result->afterUncertainResponse)->toBeFalse()
        ->and(WriteFake::times('PATCH /articles/3001'))->toBe(1);
});

it('leaves before and written empty when it failed before writing', function () {
    WriteFake::start(['GET /articles/3001' => array_fill(0, 5, WriteFake::networkFailure())]);

    $result = app(AdjustStock::class)->run(3001, -1);

    expect($result->before)->toBeNull()->and($result->written)->toBeNull();
});

it('refuses an expectation that is not a number', function () {
    expect(fn () => app(AdjustStock::class)->run(3001, -1, new StockExpectation('sept', '6')))->toThrow(InvalidDraftException::class);
});

it('tags each of its logged calls with the subject the host gives', function () {
    WriteFake::start([
        'GET /articles/3001' => [WriteFake::ok(articleWithStock(7)), WriteFake::ok(articleWithStock(6))],
        'PATCH /articles/3001' => [WriteFake::ok(articleWithStock(6))],
    ]);

    RequestContext::subject('order', 1234, fn () => app(AdjustStock::class)->run(3001, -1));

    expect(array_map(fn (object $row) => [$row->kind, $row->subject_type, (int) $row->subject_id], WriteFake::logged()))->toBe([
        ['stock.read', 'order', 1234],
        ['stock.adjust', 'order', 1234],
        ['stock.verify', 'order', 1234],
    ]);
});
