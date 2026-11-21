<?php

namespace Passorde\Sinks\Mirror;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Passorde\Sinks\Contracts\PaytypeSink;

final class MirrorPaytypeSink implements PaytypeSink
{
    private MirrorTable $table;

    public function __construct()
    {
        $this->table = new MirrorTable('passorde_paytypes');
    }

    public function upsert(array $records, CarbonImmutable $syncedAt): int
    {
        $rows = [];

        foreach ($records as $record) {
            $rows[$record->id] = ['label' => $record->label];
        }

        return DB::transaction(fn (): int => $this->table->upsert($rows, $syncedAt));
    }

    public function deactivateMissing(array $seenIds, CarbonImmutable $syncedAt): int
    {
        return $this->table->deactivateMissing($seenIds, $syncedAt);
    }
}
