<?php

namespace Passorde\Sync;

final readonly class RunResult
{
    /**
     * @param  int|null  $hostRunId  The id the host gave to run(), stored in passorde_runs.host_run_id.
     */
    public function __construct(
        public SyncKind $kind,
        public RunStatus $status,
        public int $seen = 0,
        public int $changed = 0,
        public ?string $error = null,
        public ?Window $window = null,
        public ?int $hostRunId = null,
    ) {}

    public function failed(): bool
    {
        return $this->status === RunStatus::Failed;
    }
}
