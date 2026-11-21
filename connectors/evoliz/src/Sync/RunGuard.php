<?php

namespace Passorde\Sync;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Passorde\Api\CacheLocks;
use Passorde\Evoliz\EvolizConnector;
use Passorde\Licence\Exceptions\LicenceException;
use Passorde\Licence\LicenceGuard;
use Throwable;

/**
 * What every run shares: the per-kind lock, the licence check and the passorde_runs row.
 */
final class RunGuard
{
    private const DEFAULT_LOCK_SECONDS = 3600;

    public function __construct(private readonly RunLog $log, private readonly LicenceGuard $licence) {}

    /**
     * Runs $work while holding the lock of this kind. If another process holds it, nothing runs
     * and the only result is a skipped run.
     *
     * @param  Closure(): list<RunResult>  $work
     * @return list<RunResult>
     */
    public function exclusive(SyncKind $kind, ?Window $window, Closure $work, ?int $hostRunId = null): array
    {
        $lock = CacheLocks::provider()->lock('passorde:sync:'.$kind->value, $this->lockSeconds());

        if (! $lock->get()) {
            $this->log->skipped($kind, $window, $hostRunId);

            return [new RunResult($kind, RunStatus::Skipped, window: $window, hostRunId: $hostRunId)];
        }

        try {
            $this->log->closeInterrupted($kind);

            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * Records one run around $work, whose exceptions become a failed run. An expired or invalid
     * licence fails the run before $work starts, so that nothing is called.
     *
     * @param  Closure(RunCounts, CarbonImmutable): void  $work
     */
    public function recorded(SyncKind $kind, ?Window $window, Closure $work, ?int $hostRunId = null): RunResult
    {
        $id = $this->log->start($kind, $window, $hostRunId);
        $counts = new RunCounts;
        $status = RunStatus::Succeeded;
        $error = null;

        try {
            $this->licence->check(EvolizConnector::ID);
            $work($counts, CarbonImmutable::now());
        } catch (LicenceException $exception) {
            $status = RunStatus::Failed;
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            $status = RunStatus::Failed;
            $error = $exception->getMessage();
            Log::error('Passorde: synchronisation échouée.', ['kind' => $kind->value, 'error' => $error]);
        }

        $this->log->finish($id, $status, $counts, $error);

        return new RunResult($kind, $status, $counts->seen, $counts->changed, $error, $window, $hostRunId);
    }

    private function lockSeconds(): int
    {
        $seconds = config('passorde-connect.sync.lock_seconds');

        return is_numeric($seconds) ? (int) $seconds : self::DEFAULT_LOCK_SECONDS;
    }
}
