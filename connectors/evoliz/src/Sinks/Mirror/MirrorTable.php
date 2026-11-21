<?php

namespace Passorde\Sinks\Mirror;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A passorde_ table keyed by evoliz_id. The hash of the mapped columns decides whether a row changed,
 * so that fields Passorde does not map, and the way a database returns decimals, never move updated_at.
 */
final class MirrorTable
{
    private const CHUNK = 500;

    public function __construct(private readonly string $table) {}

    /**
     * @param  array<int, array<string, scalar|null>>  $rows  Columns by Evoliz id.
     * @return int How many rows were inserted or really changed.
     */
    public function upsert(array $rows, CarbonImmutable $syncedAt): int
    {
        if ($rows === []) {
            return 0;
        }

        $existing = $this->query()
            ->whereIn('evoliz_id', array_keys($rows))
            ->get(['evoliz_id', 'row_hash', 'is_active'])
            ->keyBy('evoliz_id');

        $unchanged = [];
        $changed = 0;

        foreach ($rows as $evolizId => $columns) {
            $hash = self::hash($columns);
            $current = $existing->get($evolizId);

            if ($current !== null && $current->row_hash === $hash && (bool) $current->is_active) {
                $unchanged[] = $evolizId;

                continue;
            }

            $changed++;
            $values = array_merge($columns, ['row_hash' => $hash, 'is_active' => true, 'synced_at' => $syncedAt, 'updated_at' => $syncedAt]);

            if ($current === null) {
                $this->query()->insert(array_merge($values, ['evoliz_id' => $evolizId, 'created_at' => $syncedAt]));
            } else {
                $this->query()->where('evoliz_id', $evolizId)->update($values);
            }
        }

        if ($unchanged !== []) {
            $this->query()->whereIn('evoliz_id', $unchanged)->update(['synced_at' => $syncedAt]);
        }

        return $changed;
    }

    /**
     * @param  list<int>  $seenIds
     * @return int How many rows were flagged.
     */
    public function deactivateMissing(array $seenIds, CarbonImmutable $syncedAt): int
    {
        /** @var list<int> $active */
        $active = $this->query()->where('is_active', true)->pluck('evoliz_id')->map(fn (mixed $id): int => (int) $id)->all();

        return $this->deactivate(array_values(array_diff($active, $seenIds)), $syncedAt);
    }

    /**
     * @param  list<int>  $ids
     * @return int How many rows were flagged.
     */
    public function deactivate(array $ids, CarbonImmutable $syncedAt): int
    {
        $flagged = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $flagged += $this->query()
                ->whereIn('evoliz_id', $chunk)
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => $syncedAt]);
        }

        return $flagged;
    }

    public function query(): Builder
    {
        return DB::table($this->table);
    }

    /**
     * @param  array<array-key, mixed>  $columns
     */
    public static function hash(array $columns): string
    {
        return hash('sha256', (string) json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
