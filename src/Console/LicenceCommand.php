<?php

namespace Passorde\Console;

use Illuminate\Console\Command;
use Passorde\Connectors\ConnectorRegistry;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\Exceptions\LicenceInvalidException;
use Passorde\Licence\Exceptions\LicenceStoreException;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceRefresher;
use Passorde\Licence\LicenceText;

class LicenceCommand extends Command
{
    protected $signature = 'passorde:licence
        {action : show, install or refresh}
        {path? : For install: a file holding the licence key; without it the key is asked for, hidden}';

    protected $description = 'Show, install or refresh the Passorde Connect licence key';

    public function handle(LicenceManager $manager, LicenceRefresher $refresher, LicenceStore $store, ConnectorRegistry $connectors): int
    {
        return match ($this->argument('action')) {
            'show' => $this->show($manager, $store, $connectors),
            'install' => $this->install($manager),
            'refresh' => $this->refresh($refresher),
            default => $this->failWith(LicenceText::get('command.unknown_action')),
        };
    }

    private function show(LicenceManager $manager, LicenceStore $store, ConnectorRegistry $connectors): int
    {
        $status = $manager->status();
        $licence = $status->licence;
        $none = LicenceText::get('value.none');

        // The licence token and e-mail address are deliberately left out of this output.
        $this->table([], [
            [LicenceText::get('field.state'), LicenceText::get('state.'.$status->state->value)],
            [LicenceText::get('field.reason'), $status->reason()],
            [LicenceText::get('field.licence_id'), $licence->licenceId ?? $none],
            [LicenceText::get('field.plan'), $licence->plan ?? $none],
            [LicenceText::get('field.connectors'), $licence === null ? $none : implode(', ', array_map($connectors->name(...), $licence->connectors))],
            [LicenceText::get('field.company'), $licence->company ?? $none],
            [LicenceText::get('field.issued_at'), $licence->issuedAt ?? $none],
            [LicenceText::get('field.expires_at'), $licence === null ? $none : ($licence->expiresAt ?? LicenceText::get('value.no_expiry'))],
            [LicenceText::get('field.version_max'), $licence->versionMax ?? $none],
            [LicenceText::get('field.updates_until'), $licence === null ? $none : ($licence->updatesUntil ?? LicenceText::get('value.no_limit'))],
            [LicenceText::get('field.last_refresh'), $this->lastRefresh($store) ?? LicenceText::get('value.never')],
        ]);

        foreach ($connectors->notices() as $notice) {
            $this->line($notice);
        }

        return self::SUCCESS;
    }

    private function install(LicenceManager $manager): int
    {
        $path = $this->argument('path');

        if (is_string($path) && $path !== '') {
            $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

            if ($raw === false) {
                return $this->failWith(LicenceText::get('install.unreadable', ['path' => $path]));
            }
        } else {
            // Asked for rather than taken as an argument, so that the key stays out of the shell history.
            $raw = (string) $this->secret(LicenceText::get('install.prompt'));
        }

        if (trim($raw) === '') {
            return $this->failWith(LicenceText::get('install.empty'));
        }

        try {
            $status = $manager->install($raw);
        } catch (LicenceInvalidException $exception) {
            return $this->failWith(LicenceText::get('install.refused', ['reason' => $exception->getMessage()]));
        } catch (LicenceStoreException $exception) {
            return $this->failWith(LicenceText::get('install.store_failed', ['reason' => $exception->getMessage()]));
        }

        $this->info(LicenceText::get('install.success').' '.$status->reason());

        return self::SUCCESS;
    }

    private function refresh(LicenceRefresher $refresher): int
    {
        $result = $refresher->refresh();

        if ($result->failed()) {
            return $this->failWith($result->message);
        }

        $this->info($result->message);

        return self::SUCCESS;
    }

    private function lastRefresh(LicenceStore $store): ?string
    {
        try {
            return $store->lastRefreshAt()?->format('Y-m-d H:i');
        } catch (LicenceStoreException) {
            return null;
        }
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
