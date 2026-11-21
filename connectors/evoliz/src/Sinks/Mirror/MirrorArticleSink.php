<?php

namespace Passorde\Sinks\Mirror;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Passorde\Records\ArticleRecord;
use Passorde\Sinks\Contracts\ArticleSink;

final class MirrorArticleSink implements ArticleSink
{
    private MirrorTable $table;

    public function __construct()
    {
        $this->table = new MirrorTable('passorde_articles');
    }

    public function upsert(array $records, CarbonImmutable $syncedAt): int
    {
        $rows = [];

        foreach ($records as $record) {
            $rows[$record->id] = $this->columns($record);
        }

        return DB::transaction(fn (): int => $this->table->upsert($rows, $syncedAt));
    }

    public function deactivateMissing(array $seenIds, CarbonImmutable $syncedAt): int
    {
        return $this->table->deactivateMissing($seenIds, $syncedAt);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function columns(ArticleRecord $record): array
    {
        return [
            'reference' => $record->reference,
            'designation' => $record->designation,
            'designation_raw' => $record->designationRaw,
            'unit_price_ht' => $record->unitPriceHt,
            'vat_rate' => $record->vatRate,
            'classification_id' => $record->classificationId,
            'classification_code' => $record->classificationCode,
            'supplier_name' => $record->supplierName,
            'stock_managed' => $record->stockManaged,
            'stock_qty' => $record->stockQty,
            'enabled' => $record->enabled,
        ];
    }
}
