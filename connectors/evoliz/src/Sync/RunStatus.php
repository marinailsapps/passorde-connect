<?php

namespace Passorde\Sync;

enum RunStatus: string
{
    // For hosts that record a run when they queue it; the command starts directly at Running.
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'en attente',
            self::Running => 'en cours',
            self::Succeeded => 'réussie',
            self::Failed => 'échouée',
            self::Skipped => 'ignorée',
        };
    }
}
