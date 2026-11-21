<?php

namespace Passorde\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Passorde\Records\SyncedRecord;
use Passorde\Sinks\Contracts\ListingSink;

/**
 * A kind that Evoliz returns as a full listing: what is no longer listed is flagged inactive.
 *
 * @template T of SyncedRecord
 */
abstract class ListingSync
{
    public function __construct(protected readonly RunGuard $runs) {}

    abstract protected function kind(): SyncKind;

    /**
     * @return iterable<T>
     */
    abstract protected function records(): iterable;

    /**
     * @return ListingSink<T>
     */
    abstract protected function sink(): ListingSink;

    /**
     * @param  int|null  $hostRunId  The host's own id for this run, stored in passorde_runs.host_run_id and on the result.
     */
    public function run(?int $hostRunId = null): RunResult
    {
        return $this->runs->exclusive(
            $this->kind(),
            null,
            fn (): array => [$this->runs->recorded($this->kind(), null, $this->sync(...), $hostRunId)],
            $hostRunId,
        )[0];
    }

    private function sync(RunCounts $counts, CarbonImmutable $syncedAt): void
    {
        $sink = $this->sink();
        $seen = [];

        foreach (Batches::of($this->records()) as $batch) {
            $counts->changed += $sink->upsert($batch, $syncedAt);
            $counts->seen += count($batch);

            foreach ($batch as $record) {
                $seen[] = $record->evolizId();
            }
        }

        if ($seen === []) {
            // An empty answer says nothing about what exists: it must never empty the mirror.
            Log::warning('Passorde: liste Evoliz vide, aucune ligne désactivée.', ['kind' => $this->kind()->value]);

            return;
        }

        $counts->changed += $sink->deactivateMissing(array_values(array_unique($seen)), $syncedAt);
    }
}
