<?php

namespace Passorde\Records;

final readonly class DeliveryRecord implements SyncedRecord
{
    private const INVOICED_STATUS_CODES = [20, 24];

    /**
     * @param  list<DeliveryLineRecord>  $lines
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public int $id,
        public ?string $number,
        public ?string $externalNumber,
        public ?int $clientId,
        public ?string $clientName,
        public ?int $userId,
        public ?int $statusCode,
        public ?string $status,
        public bool $invoiced,
        public ?string $documentDate,
        public string $totalHt,
        public string $totalVat,
        public string $totalTtc,
        public string $totalQuantity,
        public ?string $comment,
        public array $lines,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $id = Row::id($row, 'deliveryid');

        if ($id === null) {
            return null;
        }

        $client = Row::nested($row, 'client');
        $total = Row::nested($row, 'total');
        $statusCode = Row::int($row, 'status_code');
        $lines = [];

        foreach (Row::nested($row, 'items') as $item) {
            if (is_array($item)) {
                $lines[] = DeliveryLineRecord::fromRow($item, count($lines) + 1);
            }
        }

        return new self(
            id: $id,
            number: Text::clean($row['document_number'] ?? null),
            externalNumber: Text::clean($row['external_document_number'] ?? null),
            clientId: Row::id($client, 'clientid'),
            clientName: Text::clean($client['name'] ?? null),
            userId: Row::id($row, 'userid'),
            statusCode: $statusCode,
            status: Text::clean($row['status'] ?? null),
            invoiced: in_array($statusCode, self::INVOICED_STATUS_CODES, true) || ! empty(Row::nested($row, 'status_dates')['invoice']),
            documentDate: self::date($row['documentdate'] ?? null),
            totalHt: Money::orZero($total['vat_exclude'] ?? null),
            totalVat: Money::orZero($total['vat'] ?? null),
            totalTtc: Money::orZero($total['vat_include'] ?? null),
            totalQuantity: Money::orZero($total['total_quantity'] ?? null),
            comment: Text::clean($row['comment'] ?? null) ?? Text::clean($row['comment_clean'] ?? null),
            lines: $lines,
            raw: $row,
        );
    }

    public function evolizId(): int
    {
        return $this->id;
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : null;
    }
}
