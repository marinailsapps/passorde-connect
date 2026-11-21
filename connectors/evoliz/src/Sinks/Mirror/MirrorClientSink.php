<?php

namespace Passorde\Sinks\Mirror;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Passorde\Records\ClientRecord;
use Passorde\Records\ContactRecord;
use Passorde\Sinks\Contracts\ClientSink;

/**
 * Clients and their primary contact. A contact that stops being primary is flagged inactive.
 */
final class MirrorClientSink implements ClientSink
{
    private MirrorTable $clients;

    private MirrorTable $contacts;

    public function __construct()
    {
        $this->clients = new MirrorTable('passorde_clients');
        $this->contacts = new MirrorTable('passorde_contacts');
    }

    public function upsert(array $records, CarbonImmutable $syncedAt): int
    {
        $clients = [];
        $contacts = [];

        foreach ($records as $record) {
            $clients[$record->id] = $this->clientColumns($record);

            if ($record->contact !== null) {
                $contacts[$record->contact->id] = $this->contactColumns($record->contact);
            }
        }

        return DB::transaction(function () use ($clients, $contacts, $syncedAt): int {
            $changed = $this->clients->upsert($clients, $syncedAt) + $this->contacts->upsert($contacts, $syncedAt);

            /** @var list<int> $replaced */
            $replaced = $this->contacts->query()
                ->whereIn('client_evoliz_id', array_keys($clients))
                ->whereNotIn('evoliz_id', array_keys($contacts))
                ->where('is_active', true)
                ->pluck('evoliz_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            return $changed + $this->contacts->deactivate($replaced, $syncedAt);
        });
    }

    public function deactivateMissing(array $seenIds, CarbonImmutable $syncedAt): int
    {
        $flagged = $this->clients->deactivateMissing($seenIds, $syncedAt);

        $seen = array_flip($seenIds);

        /** @var list<int> $orphans */
        $orphans = $this->contacts->query()
            ->where('is_active', true)
            ->get(['evoliz_id', 'client_evoliz_id'])
            ->reject(fn (object $contact): bool => isset($seen[(int) $contact->client_evoliz_id]))
            ->map(fn (object $contact): int => (int) $contact->evoliz_id)
            ->values()
            ->all();

        return $flagged + $this->contacts->deactivate($orphans, $syncedAt);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function clientColumns(ClientRecord $record): array
    {
        return [
            'code' => $record->code,
            'name' => $record->name,
            'address_line' => $record->addressLine,
            'postcode' => $record->postcode,
            'town' => $record->town,
            'country_iso2' => $record->countryIso2,
            'phone' => $record->phone,
            'mobile' => $record->mobile,
            'vat_number' => $record->vatNumber,
            'enabled' => $record->enabled,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function contactColumns(ContactRecord $record): array
    {
        return [
            'client_evoliz_id' => $record->clientId,
            'first_name' => $record->firstName,
            'last_name' => $record->lastName,
            'full_name' => $record->fullName,
            'email' => $record->email,
            'favourite' => $record->favourite,
            'enabled' => $record->enabled,
        ];
    }
}
