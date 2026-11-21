<?php

namespace Passorde\Licence;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Passorde\Connectors\ConnectorRegistry;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\Exceptions\LicenceStoreException;
use Throwable;

final class LicenceRefresher
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly LicenceManager $manager,
        private readonly LicenceVerifier $verifier,
        private readonly LicenceStore $store,
        private readonly string $refreshUrl,
        private readonly ConnectorRegistry $connectors,
    ) {}

    /**
     * Never throws. On any failure the current key is left as it is and one warning is logged. When there is nothing
     * to refresh (no licence installed, a lifetime licence, a licence without a token) nothing is sent or logged and
     * the result says so: skipped, not failed.
     */
    public function refresh(): RefreshResult
    {
        try {
            return $this->attempt();
        } catch (ConnectionException $exception) {
            return $this->failed('network', detail: $exception->getMessage());
        } catch (LicenceStoreException $exception) {
            return $this->failed('store_failed', detail: $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->failed('unexpected', detail: $exception::class.': '.$exception->getMessage());
        }
    }

    private function attempt(): RefreshResult
    {
        $status = $this->manager->status();
        $nothing = self::nothingToRefresh($status);

        if ($nothing !== null) {
            return RefreshResult::skipped(LicenceText::get('refresh.nothing', ['reason' => LicenceText::get('refresh.'.$nothing)]));
        }

        $licence = $status->licence;

        if ($licence === null || $licence->licenceToken === null || $licence->licenceToken === '') {
            return $this->failed('no_licence');
        }

        // Redirects are refused so that the bearer token only ever goes to the configured address.
        $response = Http::withToken($licence->licenceToken)
            ->accept('text/plain')
            ->timeout(self::TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->get(rtrim($this->refreshUrl, '/').'/licence/'.rawurlencode($licence->licenceId));

        if ($response->status() !== 200) {
            return $this->failed('http_status', ['status' => $response->status()]);
        }

        // The answer is the renewed key, one line of text. It replaces the key in place only if it is no worse: it
        // verifies, it is the same licence, it still covers every connector of this installation that the key in
        // place covers, and it does not end earlier. Anything else leaves the key in place.
        $key = LicenceKey::normalize($response->body());
        $answer = $this->verifier->verify($key);

        if ($answer->state === LicenceState::Invalid || $answer->licence === null) {
            return $this->failed('bad_answer', ['reason' => $answer->reason()]);
        }
        if ($answer->licence->licenceId !== $licence->licenceId) {
            return $this->failed('other_licence');
        }

        foreach ($this->connectors->ids() as $connector) {
            if ($licence->covers($connector) && ! $answer->licence->covers($connector)) {
                return $this->failed('connector_dropped', ['connector' => $this->connectors->name($connector)]);
            }
        }

        if (self::endsEarlier($answer->licence, $licence)) {
            return $this->failed('ends_earlier', ['date' => (string) $answer->licence->expiresAt]);
        }
        if (self::earlier($answer->licence->updatesUntil, $licence->updatesUntil)) {
            return $this->failed('updates_earlier', ['date' => (string) $answer->licence->updatesUntil]);
        }

        $this->store->replace($key);
        $this->store->markRefreshed(CarbonImmutable::now());

        return new RefreshResult(true, LicenceText::get('refresh.success'));
    }

    /**
     * A lifetime licence (no expires_at) ends last; dates are YYYY-MM-DD, verified, so they compare as strings.
     */
    private static function endsEarlier(Licence $answer, Licence $current): bool
    {
        return self::earlier($answer->expiresAt, $current->expiresAt);
    }

    /**
     * Whether the answer's date is before the current one, null meaning no limit: a limit where there was none counts.
     */
    private static function earlier(?string $answer, ?string $current): bool
    {
        return $answer !== null && ($current === null || $answer < $current);
    }

    /**
     * Whether a refresh has something to ask for: a verified licence with a refresh token, lifetime licences included
     * (an extension of their updates arrives this way). False for a licence without a token, no licence at all, and a
     * key whose signature does not verify (a refresh of the last one still fails, as it is not a case of nothing to
     * do). Lets a host skip scheduling it.
     */
    public static function isRefreshable(LicenceStatus $status): bool
    {
        $licence = $status->licence;

        return $licence !== null && $licence->licenceToken !== null && $licence->licenceToken !== '';
    }

    /**
     * The text key under refresh.* of why there is nothing to refresh, or null when a refresh is worth attempting.
     */
    private static function nothingToRefresh(LicenceStatus $status): ?string
    {
        $licence = $status->licence;

        return match (true) {
            $licence === null => $status->reasonCode === LicenceReason::NotInstalled ? 'not_installed' : null,
            ($licence->licenceToken ?? '') !== '' => null,
            $licence->expiresAt === null => 'lifetime',
            default => 'no_token',
        };
    }

    /**
     * @param  array<string, scalar|null>  $replace
     */
    private function failed(string $key, array $replace = [], ?string $detail = null): RefreshResult
    {
        $reason = LicenceText::get('refresh.'.$key, $replace);

        Log::warning('Passorde: licence refresh failed, current key kept.', array_filter([
            'reason' => $reason,
            'detail' => $detail,
        ]));

        return new RefreshResult(false, LicenceText::get('refresh.failed', ['reason' => $reason]));
    }
}
