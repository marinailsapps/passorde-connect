<?php

namespace Passorde\Licence;

use DateTimeImmutable;
use DateTimeZone;

final readonly class LicenceStatus
{
    /**
     * @param  array<string, scalar|null>  $params
     * @param  Licence|null  $licence  Set once the signature and required fields have been verified, even if a later check failed.
     * @param  DateTimeImmutable|null  $graceEndsAt  In grace or expired: the exact instant the grace ends or ended, the
     *                                               first one no longer covered: 00:00 Europe/Paris, 14 calendar days after the paid period.
     */
    public function __construct(
        public LicenceState $state,
        public LicenceReason $reasonCode,
        public array $params = [],
        public ?Licence $licence = null,
        public ?DateTimeImmutable $graceEndsAt = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function invalid(LicenceReason $reason, array $params = [], ?Licence $licence = null): self
    {
        return new self(LicenceState::Invalid, $reason, $params, $licence);
    }

    /** The params that hold a day (YYYY-MM-DD), shown in French in the text. */
    private const DAY_PARAMS = ['date', 'last_day', 'released', 'before'];

    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /**
     * The French text. Days read as « 1er janvier 2027 », as in the licence service's mails; params keep YYYY-MM-DD.
     */
    public function reason(): string
    {
        $replace = ['site' => LicenceService::SITE_URL];

        foreach ($this->params as $name => $value) {
            $replace[$name] = in_array($name, self::DAY_PARAMS, true) && is_string($value) ? self::frenchDay($value) : $value;
        }

        return LicenceText::get('reason.'.$this->reasonCode->value, $replace);
    }

    /**
     * « 14 novembre 2026 », « 1er janvier 2027 »; anything that is not a YYYY-MM-DD date is returned as it is.
     */
    public static function frenchDay(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return $value;
        }

        $day = (int) $parts[3];

        return ($day === 1 ? '1er' : (string) $day).' '.self::MONTHS[(int) $parts[2] - 1].' '.$parts[1];
    }

    /**
     * The last full day of grace, YYYY-MM-DD in Europe/Paris: the day before the one graceEndsAt falls on. The date
     * the reason text shows; null when graceEndsAt is.
     */
    public function graceLastDay(): ?string
    {
        return $this->graceEndsAt === null ? null : self::lastFullDayBefore($this->graceEndsAt);
    }

    /**
     * YYYY-MM-DD in Europe/Paris of the day before the one $end falls on: the last day wholly before $end.
     */
    public static function lastFullDayBefore(DateTimeImmutable $end): string
    {
        return $end->setTimezone(new DateTimeZone('Europe/Paris'))->modify('-1 day')->format('Y-m-d');
    }

    public function isUsable(): bool
    {
        return $this->state === LicenceState::Valid || $this->state === LicenceState::Grace;
    }
}
