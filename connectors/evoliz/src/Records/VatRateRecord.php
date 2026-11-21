<?php

namespace Passorde\Records;

final readonly class VatRateRecord
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(public int $id, public ?string $rate, public ?string $label, public array $raw) {}

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $id = Row::id($row, 'vatid', 'vatrateid', 'id');

        return $id === null ? null : new self($id, Money::orNull($row['rate'] ?? null), Text::clean($row['label'] ?? null), $row);
    }
}
