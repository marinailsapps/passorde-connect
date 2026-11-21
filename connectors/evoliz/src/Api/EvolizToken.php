<?php

namespace Passorde\Api;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Passorde\Api\Exceptions\ApiConfigurationException;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\LoginFailedException;
use Passorde\Evoliz\EvolizConnector;
use Passorde\Licence\Exceptions\LicenceExpiredException;
use Passorde\Licence\Exceptions\LicenceInvalidException;
use Passorde\Licence\LicenceGuard;

/**
 * The bearer token: Evoliz keeps it valid for 20 minutes, it is cached for 19.
 */
final class EvolizToken
{
    private const TTL_SECONDS = 19 * 60;

    public function __construct(
        private readonly RateLimitMonitor $rateLimit,
        private readonly LicenceGuard $licence,
    ) {}

    /**
     * @throws ApiConfigurationException
     * @throws ApiException
     * @throws ConnectionException
     * @throws LicenceExpiredException
     * @throws LicenceInvalidException
     */
    public function get(): string
    {
        // Checked here as well as in the client: without a valid key no token is handed out, cached or fresh.
        $this->licence->check(EvolizConnector::ID);

        $credentials = Credentials::fromConfig();
        $cached = $this->cached($credentials);

        if ($cached !== null) {
            return $cached['token'];
        }

        $token = $this->login($credentials);

        $this->store()->put($this->key($credentials), [
            'token' => $token,
            'issued_at' => CarbonImmutable::now()->getTimestamp(),
        ], self::TTL_SECONDS);

        return $token;
    }

    public function forget(): void
    {
        $this->store()->forget($this->key(Credentials::fromConfig()));
    }

    /**
     * Seconds since the cached token was issued, or null when none is cached.
     */
    public function ageSeconds(): ?int
    {
        $cached = $this->cached(Credentials::fromConfig());

        return $cached === null ? null : CarbonImmutable::now()->getTimestamp() - $cached['issued_at'];
    }

    private function login(Credentials $credentials): string
    {
        $response = $this->postLogin($credentials);
        $this->rateLimit->observe($response);

        if ($response->status() === 429) {
            $this->rateLimit->waitAfter429($response);
            $response = $this->postLogin($credentials);
            $this->rateLimit->observe($response);
        }

        if (! $response->successful()) {
            throw new LoginFailedException(ApiError::fromResponse($response));
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new ApiException(new ApiError($response->status(), 'HTTP '.$response->status().' — access_token absent de la réponse de connexion'));
        }

        return $token;
    }

    private function postLogin(Credentials $credentials): Response
    {
        try {
            return Http::acceptJson()
                ->asJson()
                ->timeout(self::seconds('timeout', 30))
                ->connectTimeout(self::seconds('connect_timeout', 10))
                ->withoutRedirecting()
                ->post($credentials->baseUrl.'/api/login', [
                    'public_key' => $credentials->publicKey,
                    'secret_key' => $credentials->secretKey(),
                ]);
        } catch (ConnectionException $exception) {
            // The original trace holds the login payload among the arguments of the HTTP client's frames.
            throw new ConnectionException($exception->getMessage());
        }
    }

    /**
     * @return array{token: string, issued_at: int}|null
     */
    private function cached(Credentials $credentials): ?array
    {
        $entry = $this->store()->get($this->key($credentials));

        return is_array($entry) && is_string($entry['token'] ?? null) && is_int($entry['issued_at'] ?? null)
            ? ['token' => $entry['token'], 'issued_at' => $entry['issued_at']]
            : null;
    }

    private function key(Credentials $credentials): string
    {
        return 'passorde:evoliz:token:'.hash('sha256', $credentials->baseUrl.'|'.$credentials->publicKey);
    }

    private function store(): Repository
    {
        $store = config('passorde-connect.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    private static function seconds(string $key, int $default): int
    {
        $value = config('passorde-connect.evoliz.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
