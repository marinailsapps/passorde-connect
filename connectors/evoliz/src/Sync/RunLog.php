<?php

namespace Passorde\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Rows of the passorde_runs table.
 */
final class RunLog
{
    private const TABLE = 'passorde_runs';

    private const MAX_ERROR_LENGTH = 1000;

    /**
     * @param  int|null  $hostRunId  Written only when given, so that a host that never gives one needs no migration.
     */
    public function start(SyncKind $kind, ?Window $window, ?int $hostRunId = null): int
    {
        $now = CarbonImmutable::now();

        return DB::table(self::TABLE)->insertGetId([
            ...($hostRunId === null ? [] : ['host_run_id' => $hostRunId]),
            'kind' => $kind->value,
            'status' => RunStatus::Running->value,
            'window_from' => $window?->from->format('Y-m-d'),
            'window_to' => $window?->to->format('Y-m-d'),
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function finish(int $id, RunStatus $status, RunCounts $counts, ?string $error = null): void
    {
        $now = CarbonImmutable::now();

        DB::table(self::TABLE)->where('id', $id)->update([
            'status' => $status->value,
            'finished_at' => $now,
            'rows_seen' => $counts->seen,
            'rows_changed' => $counts->changed,
            'error' => $error === null ? null : Str::limit($error, self::MAX_ERROR_LENGTH, '…'),
            'updated_at' => $now,
        ]);
    }

    public function skipped(SyncKind $kind, ?Window $window, ?int $hostRunId = null): void
    {
        $id = $this->start($kind, $window, $hostRunId);

        $this->finish($id, RunStatus::Skipped, new RunCounts);
    }

    /**
     * Whoever holds the lock calls this: a run of this kind still marked running was interrupted.
     */
    public function closeInterrupted(SyncKind $kind): void
    {
        $now = CarbonImmutable::now();

        DB::table(self::TABLE)
            ->where('kind', $kind->value)
            ->where('status', RunStatus::Running->value)
            ->update(['status' => RunStatus::Failed->value, 'finished_at' => $now, 'error' => 'Exécution interrompue.', 'updated_at' => $now]);
    }

    public function last(SyncKind $kind): ?stdClass
    {
        return DB::table(self::TABLE)->where('kind', $kind->value)->orderByDesc('id')->first();
    }
}
