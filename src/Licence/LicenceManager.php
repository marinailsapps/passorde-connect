<?php

namespace Passorde\Licence;

use Passorde\Connectors\ConnectorRegistry;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\Exceptions\LicenceInvalidException;
use Passorde\Licence\Exceptions\LicenceStoreException;

final class LicenceManager
{
    public function __construct(
        private readonly LicenceStore $store,
        private readonly LicenceVerifier $verifier,
        private readonly ConnectorRegistry $connectors = new ConnectorRegistry,
    ) {}

    /**
     * The state of the installed licence for that connector: by default the first connector this installation
     * provides. A licence that does not list the connector is invalid for it.
     */
    public function status(?string $connector = null): LicenceStatus
    {
        try {
            $raw = $this->store->current();
        } catch (LicenceStoreException $exception) {
            return LicenceStatus::invalid(LicenceReason::StoreUnavailable, ['detail' => $exception->getMessage()]);
        }

        if ($raw === null || trim($raw) === '') {
            return LicenceStatus::invalid(LicenceReason::NotInstalled);
        }

        return $this->named($this->verifier->verify($raw, connector: $connector ?? $this->connectors->default()));
    }

    /**
     * A connector that is not covered is named as people know it, its product name, followed by its notice.
     */
    private function named(LicenceStatus $status): LicenceStatus
    {
        if ($status->reasonCode !== LicenceReason::ConnectorNotCovered || $status->licence === null) {
            return $status;
        }

        $connector = (string) $status->params['connector'];
        $notice = $this->connectors->notice($connector);

        return LicenceStatus::invalid(LicenceReason::ConnectorNotCovered, [
            'connector' => $this->connectors->name($connector),
            'connectors' => implode(', ', array_map($this->connectors->name(...), $status->licence->connectors)),
            'notice' => $notice === null ? '' : ' '.$notice,
        ], $status->licence);
    }

    /**
     * Replaces the current licence with this key, unless it fails a check other than the date state, the connector
     * check included. An expired licence can be installed; one that is invalid cannot. The key is stored without its
     * whitespace.
     *
     * @throws LicenceInvalidException
     * @throws LicenceStoreException
     */
    public function install(string $raw): LicenceStatus
    {
        $key = LicenceKey::normalize($raw);
        $status = $this->named($this->verifier->verify($key, connector: $this->connectors->default()));

        if ($status->state === LicenceState::Invalid) {
            throw new LicenceInvalidException($status);
        }

        $this->store->replace($key);

        return $status;
    }
}
