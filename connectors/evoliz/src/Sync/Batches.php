<?php

namespace Passorde\Sync;

use Generator;

final class Batches
{
    private const SIZE = 100;

    /**
     * @template T
     *
     * @param  iterable<T>  $items
     * @return Generator<int, non-empty-list<T>>
     */
    public static function of(iterable $items): Generator
    {
        $batch = [];

        foreach ($items as $item) {
            $batch[] = $item;

            if (count($batch) >= self::SIZE) {
                yield $batch;
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield $batch;
        }
    }
}
