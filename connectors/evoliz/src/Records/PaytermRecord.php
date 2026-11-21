<?php

namespace Passorde\Records;

final readonly class PaytermRecord
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(public int $id, public ?string $label, public array $raw) {}

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $id = Row::id($row, 'paytermid', 'id');

        return $id === null ? null : new self($id, Text::clean($row['label'] ?? null), $row);
    }
}
