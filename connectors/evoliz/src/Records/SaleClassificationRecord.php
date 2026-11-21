<?php

namespace Passorde\Records;

final readonly class SaleClassificationRecord
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(public int $id, public ?string $code, public ?string $label, public array $raw) {}

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $id = Row::id($row, 'classificationid', 'saleclassificationid', 'id');

        return $id === null ? null : new self($id, Text::clean($row['code'] ?? null), Text::clean($row['label'] ?? null), $row);
    }
}
