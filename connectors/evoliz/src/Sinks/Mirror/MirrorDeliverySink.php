<?php

namespace Passorde\Sinks\Mirror;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Passorde\Records\DeliveryLineRecord;
use Passorde\Records\DeliveryRecord;
use Passorde\Sinks\Contracts\DeliverySink;

/**
 * Deliveries and their lines. Lines have no identifier at Evoliz, so they are replaced all together
 * when their hash changes, and all carry the same timestamp.
 */
final class MirrorDeliverySink implements DeliverySink
{
    private const DELIVERIES = 'passorde_deliveries';

    private const LINES = 'passorde_delivery_lines';

    private const CHUNK = 500;

    public function upsert(array $records, CarbonImmutable $syncedAt): int
    {
        return DB::transaction(function () use ($records, $syncedAt): int {
            $changed = 0;

            foreach ($records as $record) {
                $changed += $this->upsertOne($record, $syncedAt) ? 1 : 0;
            }

            return $changed;
        });
    }

    public function removeMissing(CarbonImmutable $from, CarbonImmutable $to, array $seenIds, CarbonImmutable $syncedAt): int
    {
        /** @var list<int> $inWindow */
        $inWindow = DB::table(self::DELIVERIES)
            ->whereNull('deleted_at')
            ->whereBetween('document_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->pluck('evoliz_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $removed = 0;

        foreach (array_chunk(array_values(array_diff($inWindow, $seenIds)), self::CHUNK) as $chunk) {
            $removed += DB::table(self::DELIVERIES)
                ->whereIn('evoliz_id', $chunk)
                ->update(['deleted_at' => $syncedAt, 'is_active' => false, 'updated_at' => $syncedAt]);
        }

        return $removed;
    }

    private function upsertOne(DeliveryRecord $record, CarbonImmutable $syncedAt): bool
    {
        $columns = $this->columns($record);
        $lines = array_map($this->lineColumns(...), $record->lines);
        $rowHash = MirrorTable::hash($columns);
        $linesHash = MirrorTable::hash($lines);
        $current = DB::table(self::DELIVERIES)->where('evoliz_id', $record->id)->first(['id', 'row_hash', 'lines_hash', 'deleted_at']);

        if ($current === null) {
            $deliveryId = DB::table(self::DELIVERIES)->insertGetId(array_merge($columns, [
                'evoliz_id' => $record->id,
                'row_hash' => $rowHash,
                'lines_hash' => $linesHash,
                'is_active' => true,
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ]));
            $this->writeLines($deliveryId, $lines, $syncedAt);

            return true;
        }

        $linesChanged = $current->lines_hash !== $linesHash;

        if ($current->row_hash === $rowHash && ! $linesChanged && $current->deleted_at === null) {
            DB::table(self::DELIVERIES)->where('id', $current->id)->update(['synced_at' => $syncedAt]);

            return false;
        }

        DB::table(self::DELIVERIES)->where('id', $current->id)->update(array_merge($columns, [
            'row_hash' => $rowHash,
            'lines_hash' => $linesHash,
            'is_active' => true,
            'deleted_at' => null,
            'synced_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ]));

        if ($linesChanged) {
            $this->writeLines((int) $current->id, $lines, $syncedAt);
        }

        return true;
    }

    /**
     * @param  list<array<string, scalar|null>>  $lines
     */
    private function writeLines(int $deliveryId, array $lines, CarbonImmutable $syncedAt): void
    {
        DB::table(self::LINES)->where('delivery_id', $deliveryId)->delete();

        $rows = array_map(fn (array $line): array => array_merge($line, [
            'delivery_id' => $deliveryId,
            'synced_at' => $syncedAt,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ]), $lines);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table(self::LINES)->insert($chunk);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function columns(DeliveryRecord $record): array
    {
        return [
            'number' => $record->number,
            'external_number' => $record->externalNumber,
            'client_evoliz_id' => $record->clientId,
            'client_name' => $record->clientName,
            'user_evoliz_id' => $record->userId,
            'status_code' => $record->statusCode,
            'status' => $record->status,
            'invoiced' => $record->invoiced,
            'document_date' => $record->documentDate,
            'total_ht' => $record->totalHt,
            'total_vat' => $record->totalVat,
            'total_ttc' => $record->totalTtc,
            'total_quantity' => $record->totalQuantity,
            'comment' => $record->comment,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function lineColumns(DeliveryLineRecord $line): array
    {
        return [
            'position' => $line->position,
            'article_evoliz_id' => $line->articleId,
            'reference' => $line->reference,
            'designation' => $line->designation,
            'quantity' => $line->quantity,
            'unit_price_ht' => $line->unitPriceHt,
            'vat_rate' => $line->vatRate,
            'total_ht' => $line->totalHt,
            'is_free' => $line->isFree,
        ];
    }
}
