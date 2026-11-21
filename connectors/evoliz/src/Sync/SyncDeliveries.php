<?php

namespace Passorde\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Passorde\Reads\ReadDeliveries;
use Passorde\Sinks\Contracts\DeliverySink;

final class SyncDeliveries
{
    private const DEFAULT_DAYS_BACK = 7;

    private const DEFAULT_DAYS_AHEAD = 31;

    public function __construct(
        private readonly RunGuard $runs,
        private readonly ReadDeliveries $reader,
        private readonly DeliverySink $sink,
    ) {}

    /**
     * The last 7 days and the next 31, unless told otherwise: documents may be dated in the future.
     */
    public static function defaultWindow(?int $daysBack = null): Window
    {
        $today = CarbonImmutable::now();

        return new Window($today->subDays($daysBack ?? self::DEFAULT_DAYS_BACK), $today->addDays(self::DEFAULT_DAYS_AHEAD));
    }

    /**
     * One run per window, stopping at the first failure: a later run covers the same dates again.
     *
     * @param  int|null  $hostRunId  The host's own id for this run, stored on every passorde_runs row and result it gives.
     * @return list<RunResult>
     */
    public function run(?Window $window = null, ?int $hostRunId = null): array
    {
        $window ??= self::defaultWindow();

        return $this->runs->exclusive(SyncKind::Deliveries, $window, function () use ($window, $hostRunId): array {
            $results = [];

            foreach ($window->split() as $part) {
                $results[] = $result = $this->runs->recorded(
                    SyncKind::Deliveries,
                    $part,
                    fn (RunCounts $counts, CarbonImmutable $syncedAt) => $this->sync($part, $counts, $syncedAt),
                    $hostRunId,
                );

                if ($result->failed()) {
                    break;
                }
            }

            return $results;
        }, $hostRunId);
    }

    private function sync(Window $window, RunCounts $counts, CarbonImmutable $syncedAt): void
    {
        $seen = [];

        foreach (Batches::of($this->reader->window($window->from, $window->to)) as $batch) {
            $counts->changed += $this->sink->upsert($batch, $syncedAt);
            $counts->seen += count($batch);

            foreach ($batch as $record) {
                $seen[] = $record->id;
            }
        }

        if ($seen === []) {
            Log::warning('Passorde: aucun bon de livraison renvoyé, aucune suppression.', ['window' => $window->label()]);

            return;
        }

        $counts->changed += $this->sink->removeMissing($window->from, $window->to, array_values(array_unique($seen)), $syncedAt);
    }
}
