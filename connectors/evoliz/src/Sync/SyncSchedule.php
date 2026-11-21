<?php

namespace Passorde\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Events run one after the other, on purpose: Evoliz allows 100 calls per minute per IP.
 * The nightly times avoid the quarter-hour ticks, which would make the per-kind lock skip one of the two.
 */
final class SyncSchedule
{
    public static function register(Schedule $schedule): void
    {
        [$firstHour, $endHour] = self::workingHours();

        self::configure($schedule->command('passorde:sync articles')->cron(sprintf('*/15 %d-%d * * 1-5', $firstHour, $endHour - 1)));
        self::configure($schedule->command('passorde:sync articles')->hourly()->skip(
            fn (): bool => self::isWorkingHour(CarbonImmutable::now(self::timezone() ?? date_default_timezone_get()), $firstHour, $endHour),
        ));
        self::configure($schedule->command('passorde:sync deliveries --days=7')->everyFifteenMinutes());
        self::configure($schedule->command('passorde:sync clients')->cron('7 2 * * *'));
        self::configure($schedule->command('passorde:sync paytypes')->cron('22 2 * * *'));
        self::configure($schedule->command('passorde:sync deliveries --days=60')->cron('37 2 * * *'));
    }

    private static function configure(Event $event): void
    {
        $minutes = config('passorde-connect.schedule.overlap_minutes');
        $event->withoutOverlapping(is_numeric($minutes) ? (int) $minutes : 30);

        $timezone = self::timezone();

        if ($timezone !== null) {
            $event->timezone($timezone);
        }
    }

    private static function isWorkingHour(CarbonImmutable $now, int $firstHour, int $endHour): bool
    {
        return $now->isWeekday() && $now->hour >= $firstHour && $now->hour < $endHour;
    }

    /**
     * @return array{int, int}
     */
    private static function workingHours(): array
    {
        $hours = config('passorde-connect.schedule.working_hours');

        return is_array($hours) && is_numeric($hours[0] ?? null) && is_numeric($hours[1] ?? null)
            ? [(int) $hours[0], (int) $hours[1]]
            : [8, 19];
    }

    private static function timezone(): ?string
    {
        $timezone = config('passorde-connect.schedule.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }
}
