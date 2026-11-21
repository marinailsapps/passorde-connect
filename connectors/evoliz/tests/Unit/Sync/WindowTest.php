<?php

use Carbon\CarbonImmutable;
use Passorde\Sync\Window;

function windowOf(string $from, string $to): Window
{
    return new Window(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
}

it('counts both ends', function () {
    expect(windowOf('2026-06-01', '2026-06-01')->days())->toBe(1)
        ->and(windowOf('2026-06-01', '2026-06-30')->days())->toBe(30);
});

it('refuses an inverted window', function () {
    windowOf('2026-06-02', '2026-06-01');
})->throws(InvalidArgumentException::class, 'inversée');

it('stays whole up to 100 days', function () {
    $window = windowOf('2026-01-01', '2026-04-10');

    expect($window->days())->toBe(100)
        ->and($window->split())->toBe([$window]);
});

it('splits by calendar month beyond 100 days', function () {
    $parts = windowOf('2026-01-15', '2026-05-03')->split();

    expect(array_map(fn (Window $part) => $part->label(), $parts))->toBe([
        '2026-01-15 → 2026-01-31',
        '2026-02-01 → 2026-02-28',
        '2026-03-01 → 2026-03-31',
        '2026-04-01 → 2026-04-30',
        '2026-05-01 → 2026-05-03',
    ]);
});
