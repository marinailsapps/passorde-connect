<?php

namespace Passorde\Sinks\Mirror;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Passorde\Sinks\Contracts\UserSink;

final class MirrorUserSink implements UserSink
{
    private MirrorTable $table;

    public function __construct()
    {
        $this->table = new MirrorTable('passorde_users');
    }

    public function upsert(array $records, CarbonImmutable $syncedAt): int
    {
        $rows = [];

        foreach ($records as $record) {
            $rows[$record->id] = [
                'first_name' => $record->firstName,
                'last_name' => $record->lastName,
                'full_name' => $record->fullName,
                'email' => $record->email,
            ];
        }

        return DB::transaction(fn (): int => $this->table->upsert($rows, $syncedAt));
    }

    public function deactivateMissing(array $seenIds, CarbonImmutable $syncedAt): int
    {
        return $this->table->deactivateMissing($seenIds, $syncedAt);
    }
}
