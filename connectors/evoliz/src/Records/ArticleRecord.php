<?php

namespace Passorde\Records;

final readonly class ArticleRecord implements SyncedRecord
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public int $id,
        public ?string $reference,
        public ?string $designation,
        public ?string $designationRaw,
        public string $unitPriceHt,
        public ?string $vatRate,
        public ?int $classificationId,
        public ?string $classificationCode,
        public ?string $supplierName,
        public bool $stockManaged,
        public ?string $stockQty,
        public bool $enabled,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $id = Row::id($row, 'articleid');

        if ($id === null) {
            return null;
        }

        $classification = Row::nested($row, 'sale_classification');
        $designation = $row['designation'] ?? null;

        return new self(
            id: $id,
            reference: Text::clean($row['reference'] ?? null),
            designation: Text::clean($designation) ?? Text::clean($row['designation_clean'] ?? null),
            designationRaw: is_string($designation) ? $designation : null,
            unitPriceHt: Money::orZero($row['unit_price_vat_exclude'] ?? null),
            vatRate: Money::orNull($row['vat'] ?? null),
            classificationId: Row::id($classification, 'id'),
            classificationCode: Text::clean($classification['code'] ?? null),
            supplierName: Text::clean(Row::nested($row, 'supplier')['name'] ?? null),
            stockManaged: Row::bool($row, 'stock_management', false),
            stockQty: Money::orNull($row['stocked_quantity'] ?? null),
            enabled: Row::bool($row, 'enabled', true),
            raw: $row,
        );
    }

    public function evolizId(): int
    {
        return $this->id;
    }
}
