<?php

namespace Passorde\Console;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Passorde\Sync\RunResult;
use Passorde\Sync\SyncArticles;
use Passorde\Sync\SyncClients;
use Passorde\Sync\SyncDeliveries;
use Passorde\Sync\SyncKind;
use Passorde\Sync\SyncPaytypes;
use Passorde\Sync\SyncUsers;
use Passorde\Sync\Window;
use Throwable;

class SyncCommand extends Command
{
    protected $signature = 'passorde:sync
        {kind : clients|articles|paytypes|deliveries|users|all}
        {--since= : Deliveries from this date (Y-m-d)}
        {--until= : Deliveries up to this date (Y-m-d)}
        {--days= : Deliveries of the last N days}';

    protected $description = 'Read Evoliz into the sinks. Exit code: 0 ok or skipped, 1 failure';

    public function handle(): int
    {
        $kind = $this->argument('kind');
        $kinds = $kind === 'all' ? SyncKind::cases() : array_filter([SyncKind::tryFrom(is_string($kind) ? $kind : '')]);

        if ($kinds === []) {
            $this->error('Type inconnu : clients, articles, paytypes, deliveries, users ou all.');

            return self::FAILURE;
        }

        try {
            $window = $this->window();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($window !== null && ! in_array(SyncKind::Deliveries, $kinds, true)) {
            $this->warn('--since, --until et --days ne concernent que deliveries.');
        }

        $failed = false;

        foreach ($kinds as $current) {
            try {
                foreach ($this->runKind($current, $window) as $result) {
                    $this->report($result);
                    $failed = $failed || $result->failed();
                }
            } catch (Throwable $exception) {
                // The run could not even be recorded: usually a missing migration or an unreachable database.
                $this->error($current->value.' : '.$exception->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<RunResult>
     */
    private function runKind(SyncKind $kind, ?Window $window): array
    {
        return match ($kind) {
            SyncKind::Paytypes => [$this->laravel->make(SyncPaytypes::class)->run()],
            SyncKind::Clients => [$this->laravel->make(SyncClients::class)->run()],
            SyncKind::Articles => [$this->laravel->make(SyncArticles::class)->run()],
            SyncKind::Users => [$this->laravel->make(SyncUsers::class)->run()],
            SyncKind::Deliveries => $this->laravel->make(SyncDeliveries::class)->run($window),
        };
    }

    private function report(RunResult $result): void
    {
        $line = $result->kind->value
            .($result->window === null ? '' : ' ['.$result->window->label().']')
            .' : '.$result->status->label()
            .' — vues '.$result->seen.', modifiées '.$result->changed;

        if ($result->failed()) {
            $this->error($line.' — '.$result->error);
        } else {
            $this->line($line);
        }
    }

    /**
     * @return Window|null Null means the default window.
     *
     * @throws InvalidArgumentException
     */
    private function window(): ?Window
    {
        $since = $this->date('since');
        $until = $this->date('until');
        $days = $this->option('days');

        if ($days !== null && (! is_string($days) || ! ctype_digit($days))) {
            throw new InvalidArgumentException('--days attend un nombre de jours.');
        }
        if ($days !== null && $since !== null) {
            throw new InvalidArgumentException('--days et --since ne peuvent pas être utilisés ensemble.');
        }
        if ($since === null && $until === null && $days === null) {
            return null;
        }

        $default = SyncDeliveries::defaultWindow($days === null ? null : (int) $days);

        // --until alone keeps the default length back from that date.
        return new Window($since ?? ($until !== null && $days === null ? $until->subDays(7) : $default->from), $until ?? $default->to);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function date(string $option): ?CarbonImmutable
    {
        $value = $this->option($option);

        if ($value === null) {
            return null;
        }

        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('--'.$option.' attend une date au format AAAA-MM-JJ.');
        }

        return CarbonImmutable::instance($date);
    }
}
