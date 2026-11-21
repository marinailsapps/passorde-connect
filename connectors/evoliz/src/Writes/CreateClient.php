<?php

namespace Passorde\Writes;

use Passorde\Drafts\ClientDraft;
use Passorde\Evoliz\EvolizConnector;
use Passorde\Licence\LicenceGuard;
use Passorde\Records\ClientRecord;
use Passorde\Records\Text;
use Passorde\Writes\Results\AlreadyExists;
use Passorde\Writes\Results\Created;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Support\ClientCode;
use Passorde\Writes\Support\Mirror;
use Passorde\Writes\Support\WriteCalls;
use Passorde\Writes\Support\WriteLock;

final class CreateClient
{
    public function __construct(
        private readonly WriteCalls $calls,
        private readonly Mirror $mirror,
        private readonly WriteLock $lock,
        private readonly LicenceGuard $licence,
    ) {}

    /**
     * Finds the client by its code, or creates it under the first code free. Repeating the call is safe:
     * the code of the same client, by name and postcode, gives back the same client.
     *
     * @return Created<ClientRecord>|AlreadyExists<ClientRecord>|Refused|Failed
     */
    public function run(ClientDraft $draft): Created|AlreadyExists|Refused|Failed
    {
        // Second line of defence: the client checks again, but a host may bind its own EvolizApi.
        $this->licence->check(EvolizConnector::ID);

        return $this->lock->around(
            'client:'.hash('sha256', mb_strtolower(trim($draft->name)).'|'.trim($draft->postcode ?? '')),
            fn () => $this->findOrCreate($draft),
        );
    }

    /**
     * @return Created<ClientRecord>|AlreadyExists<ClientRecord>|Refused|Failed
     */
    private function findOrCreate(ClientDraft $draft): Created|AlreadyExists|Refused|Failed
    {
        foreach (ClientCode::candidates($draft) as $code) {
            $row = $this->byCode($code);

            if ($row !== null && ! ClientCode::isSameClient($row, $draft)) {
                continue;
            }

            if ($row !== null) {
                $record = $this->mirror->client($row);

                return $record === null ? self::unreadable() : new AlreadyExists($record);
            }

            return $this->create($draft, $code);
        }

        return new Failed(null, 'Aucun code client libre pour '.trim($draft->name).'.');
    }

    /**
     * @return Created<ClientRecord>|AlreadyExists<ClientRecord>|Refused|Failed
     */
    private function create(ClientDraft $draft, string $code): Created|AlreadyExists|Refused|Failed
    {
        $sent = $this->calls->send('client.create', 'POST', '/clients', $draft->toRequest($code), function () use ($code, $draft): ?array {
            $row = $this->byCode($code, singleAttempt: true);

            return $row !== null && ClientCode::isSameClient($row, $draft) ? $row : null;
        });

        if ($sent->problem !== null) {
            return $sent->problem;
        }

        if ($sent->recovered) {
            $record = $this->mirror->client($sent->row);

            return $record === null ? self::unreadable() : new AlreadyExists($record, afterUncertainResponse: true);
        }

        $record = ClientRecord::fromRow($sent->row);

        if ($record === null) {
            return self::unreadable();
        }

        $this->mirror->newClient($record);

        return new Created($record);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function byCode(string $code, bool $singleAttempt = false): ?array
    {
        return $this->calls->first(
            'client.lookup',
            '/clients',
            ['code' => $code],
            fn (array $row): bool => Text::clean($row['code'] ?? null) === Text::clean($code),
            $singleAttempt,
        );
    }

    private static function unreadable(): Failed
    {
        return new Failed(null, 'Evoliz a répondu sans identifiant de client.', uncertain: true);
    }
}
