<?php

namespace Passorde\Records;

final readonly class PaytypeRecord implements SyncedRecord
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
        $id = Row::id($row, 'paytypeid');

        return $id === null ? null : new self($id, Text::clean($row['label'] ?? null), $row);
    }

    public function evolizId(): int
    {
        return $this->id;
    }
}
