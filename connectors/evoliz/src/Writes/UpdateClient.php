<?php

namespace Passorde\Writes;

use Passorde\Api\Exceptions\ApiException;
use Passorde\Drafts\ClientPatch;
use Passorde\Drafts\Input;
use Passorde\Evoliz\EvolizConnector;
use Passorde\Licence\LicenceGuard;
use Passorde\Records\ClientRecord;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Results\Updated;
use Passorde\Writes\Support\Mirror;
use Passorde\Writes\Support\Stored;
use Passorde\Writes\Support\WriteCalls;
use Passorde\Writes\Support\WriteLock;

final class UpdateClient
{
    public function __construct(
        private readonly WriteCalls $calls,
        private readonly Mirror $mirror,
        private readonly WriteLock $lock,
        private readonly LicenceGuard $licence,
    ) {}

    /**
     * Evoliz may answer 500 and apply the change all the same: the client is read back before any retry,
     * and a change found applied is a success.
     *
     * @return Updated<ClientRecord>|Refused|Failed
     */
    public function run(int $clientId, ClientPatch $patch): Updated|Refused|Failed
    {
        // Second line of defence: the client checks again, but a host may bind its own EvolizApi.
        $this->licence->check(EvolizConnector::ID);

        Input::id('clientId', $clientId);

        return $this->lock->around('client-update:'.$clientId, function () use ($clientId, $patch): Updated|Refused|Failed {
            $path = '/clients/'.$clientId;
            $body = $patch->toRequest();

            $sent = $this->calls->send('client.update', 'PATCH', $path, $body, function () use ($path, $body): ?array {
                $row = $this->calls->readOnce('client.verify', $path);

                return Stored::holds($row, $body) ? $row : null;
            });

            if ($sent->problem !== null) {
                return $sent->problem;
            }

            try {
                $row = isset($sent->row['clientid']) ? $sent->row : $this->calls->readOnce('client.read', $path);
            } catch (ApiException $exception) {
                return new Failed($exception->error->status, $exception->getMessage(), uncertain: true);
            }

            $record = $this->mirror->client($row);

            return $record === null
                ? new Failed(null, 'Evoliz a répondu sans identifiant de client.', uncertain: true)
                : new Updated($record, $sent->recovered);
        });
    }
}
