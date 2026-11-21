<?php

namespace Passorde\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceText;
use Passorde\Sync\RunLog;
use Passorde\Sync\RunStatus;
use Passorde\Sync\SyncKind;
use stdClass;
use Throwable;

class StatusCommand extends Command
{
    protected $signature = 'passorde:status';

    protected $description = 'Print the last run per kind and the licence state. Exit code: 0 valid, 1 grace, 2 expired or invalid';

    public function handle(LicenceManager $manager, RunLog $runs): int
    {
        $this->printRuns($runs);

        $status = $manager->status();

        $this->line(LicenceText::get('state.'.$status->state->value).' : '.$status->reason());

        return $status->state->exitCode();
    }

    private function printRuns(RunLog $runs): void
    {
        try {
            foreach (SyncKind::cases() as $kind) {
                $this->line($kind->value.' : '.$this->describe($runs->last($kind)));
            }
        } catch (Throwable) {
            // This command must keep answering about the licence without a database or before the migrations.
            $this->line('Dernières synchronisations indisponibles : migrations à exécuter ou base inaccessible.');
        }
    }

    private function describe(?stdClass $run): string
    {
        if ($run === null) {
            return 'jamais exécutée';
        }

        $parts = [
            CarbonImmutable::parse($run->started_at ?? $run->created_at)->format('Y-m-d H:i'),
            RunStatus::from($run->status)->label(),
            'vues '.$run->rows_seen.', modifiées '.$run->rows_changed,
        ];

        if ($run->error !== null) {
            $parts[] = $run->error;
        }

        return implode(' — ', $parts);
    }
}
