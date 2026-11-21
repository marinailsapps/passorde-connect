<?php

namespace Passorde\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\EvolizToken;
use Passorde\Api\Exceptions\ApiConfigurationException;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\ForbiddenPathException;
use Passorde\Api\RateLimitMonitor;
use Passorde\Evoliz\EvolizConnector;
use Passorde\Licence\Exceptions\LicenceException;
use Passorde\Licence\LicenceGuard;

class PingCommand extends Command
{
    private const LICENCE_BLOCKED = 2;

    protected $signature = 'passorde:ping';

    protected $description = 'Log in to Evoliz and make one cheap call. Exit code: 0 ok, 1 failure, 2 blocked by the licence';

    public function handle(LicenceGuard $licence, EvolizApi $api, EvolizToken $token, RateLimitMonitor $rateLimit): int
    {
        try {
            // Checked here first, as a sync run does, so that a host whose transport refuses every call without a
            // usable licence (Passorde standalone) gets the licence sentence rather than its transport's error.
            $licence->check(EvolizConnector::ID);
            $api->get('/vat-rates', ['per_page' => 1]);
        } catch (LicenceException $exception) {
            $this->error('Refusé par la licence : '.$exception->getMessage());

            return self::LICENCE_BLOCKED;
        } catch (ApiException|ApiConfigurationException|ConnectionException|ForbiddenPathException $exception) {
            $this->error('Échec : '.$exception->getMessage());

            return self::FAILURE;
        }

        $company = config('passorde-connect.evoliz.company_id');

        $this->info('Evoliz répond.');
        $this->line('Société : '.(is_scalar($company) ? $company : ''));
        $this->line('Appels restants : '.($rateLimit->remaining() ?? 'inconnu'));
        $this->line('Âge du jeton : '.($token->ageSeconds() ?? 0).' s');

        return self::SUCCESS;
    }
}
