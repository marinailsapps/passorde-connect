<?php

namespace Passorde\Reads;

use Closure;
use Generator;
use Illuminate\Support\Facades\Log;
use Passorde\Api\Contracts\EvolizApi;

/**
 * Turns a paginated listing into records, one page at a time.
 */
final class Listing
{
    private const PER_PAGE = 100;

    public function __construct(private readonly EvolizApi $api) {}

    /**
     * A row without an identifier cannot be mirrored: it is skipped with a warning.
     *
     * @template T of object
     *
     * @param  array<string, mixed>  $query
     * @param  Closure(array<array-key, mixed>): (T|null)  $map
     * @return Generator<int, T>
     */
    public function records(string $path, array $query, Closure $map): Generator
    {
        foreach ($this->api->paginate($path, $query, self::PER_PAGE) as $rows) {
            foreach ($rows as $row) {
                $record = is_array($row) ? $map($row) : null;

                if ($record === null) {
                    Log::warning('Passorde: ligne Evoliz sans identifiant ignorée.', ['path' => $path]);

                    continue;
                }

                yield $record;
            }
        }
    }
}
