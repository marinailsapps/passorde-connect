<?php

namespace Passorde\Records;

final readonly class DeliveryLineRecord
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public int $position,
        public ?int $articleId,
        public ?string $reference,
        public ?string $designation,
        public string $quantity,
        public string $unitPriceHt,
        public ?string $vatRate,
        public string $totalHt,
        public bool $isFree,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function fromRow(array $row, int $position): self
    {
        $unitPrice = Money::orZero($row['unit_price_vat_exclude'] ?? null);

        return new self(
            position: $position,
            articleId: Row::id($row, 'articleid'),
            reference: Text::clean($row['reference'] ?? null),
            designation: Text::clean($row['designation'] ?? null) ?? Text::clean($row['designation_clean'] ?? null),
            quantity: Money::orZero($row['quantity'] ?? null),
            unitPriceHt: $unitPrice,
            vatRate: Money::orNull($row['vat'] ?? null),
            totalHt: Money::orZero(Row::nested($row, 'total')['vat_exclude'] ?? null),
            isFree: $unitPrice === Money::ZERO,
            raw: $row,
        );
    }
}
