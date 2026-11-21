<?php

namespace Passorde\Sync;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Two calendar dates, both included.
 */
final readonly class Window
{
    private const MAX_DAYS = 100;

    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(CarbonImmutable $from, CarbonImmutable $to)
    {
        $this->from = $from->startOfDay();
        $this->to = $to->startOfDay();

        if ($this->from->greaterThan($this->to)) {
            throw new InvalidArgumentException(sprintf('La période est inversée : %s après %s.', $this->from->format('Y-m-d'), $this->to->format('Y-m-d')));
        }
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * Itself, or one window per calendar month when it is longer than 100 days.
     *
     * @return list<self>
     */
    public function split(): array
    {
        if ($this->days() <= self::MAX_DAYS) {
            return [$this];
        }

        $windows = [];

        for ($start = $this->from; $start->lessThanOrEqualTo($this->to); $start = $start->endOfMonth()->addDay()->startOfDay()) {
            $windows[] = new self($start, $start->endOfMonth()->min($this->to));
        }

        return $windows;
    }

    public function label(): string
    {
        return $this->from->format('Y-m-d').' → '.$this->to->format('Y-m-d');
    }
}
