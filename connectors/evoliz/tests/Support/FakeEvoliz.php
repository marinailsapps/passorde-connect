<?php

namespace Passorde\Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Passorde\Licence\LicenceManager;

/**
 * A fictional Evoliz account for the transport tests.
 */
final class FakeEvoliz
{
    public const BASE = 'https://evoliz.test';

    public const COMPANY = 12345;

    public const PUBLIC_KEY = 'fictional-public-key';

    public const SECRET = 'fictional-secret-value';

    public const TOKEN = 'fictional-access-token';

    public const LOGIN = 'evoliz.test/api/login';

    public const SCOPE = 'evoliz.test/api/v1/companies/12345';

    public static function configure(bool $writes = false): void
    {
        config()->set('passorde-connect.evoliz.base_url', self::BASE.'/');
        config()->set('passorde-connect.evoliz.company_id', self::COMPANY);
        config()->set('passorde-connect.evoliz.public_key', self::PUBLIC_KEY);
        config()->set('passorde-connect.evoliz.secret_key', self::SECRET);
        config()->set('passorde-connect.writes_enabled', $writes);
        config()->set('passorde-connect.request_log.driver', 'null');

        app(LicenceManager::class)->install(TestLicence::signed());
    }

    /**
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../fixtures/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $routes
     */
    public static function fake(array $routes = []): void
    {
        Http::fake(array_merge([self::LOGIN => Http::response(self::fixture('login'))], $routes));
    }

    public static function logins(): int
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/login'))->count();
    }

    /**
     * @return list<Request>
     */
    public static function calls(): array
    {
        return Http::recorded(fn (Request $request) => ! str_ends_with($request->url(), '/api/login'))
            ->map(fn (array $pair) => $pair[0])
            ->values()
            ->all();
    }
}
