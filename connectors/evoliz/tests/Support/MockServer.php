<?php

namespace Passorde\Tests\Support;

use Illuminate\Support\Facades\Http;
use Passorde\Api\Contracts\RequestLog;
use Passorde\Licence\LicenceManager;
use RuntimeException;

/**
 * mock/index.php under PHP's built-in server, started once per test run and reset before each test.
 * The tests reach it over real HTTP, so nothing of Http::fake() stands between Passorde and the answers.
 */
final class MockServer
{
    public const TODAY = '2026-06-15';

    public const COMPANY = 1000;

    public const PUBLIC_KEY = 'mock-public-key';

    public const SECRET = 'mock-secret-value';

    private static ?self $running = null;

    /**
     * @param  resource  $process
     */
    private function __construct(private $process, public readonly string $base, private readonly string $stateFile) {}

    /**
     * Starts the server if needed and points Passorde at it, with fresh data and a valid licence.
     */
    public static function prepare(bool $writes = true): self
    {
        $server = self::$running ??= self::start();
        $server->reset();

        Http::allowStrayRequests();

        config()->set('passorde-connect.evoliz.base_url', $server->base);
        config()->set('passorde-connect.evoliz.company_id', self::COMPANY);
        config()->set('passorde-connect.evoliz.public_key', self::PUBLIC_KEY);
        config()->set('passorde-connect.evoliz.secret_key', self::SECRET);
        config()->set('passorde-connect.writes_enabled', $writes);
        config()->set('passorde-connect.request_log.driver', 'database');
        app()->forgetInstance(RequestLog::class);

        (require __DIR__.'/../../../../database/migrations/2026_01_01_000000_create_passorde_requests_table.php')->up();

        foreach (['000001_create_passorde_runs_table', '000002_create_passorde_mirror_tables'] as $migration) {
            (require __DIR__.'/../../database/migrations/2026_01_01_'.$migration.'.php')->up();
        }

        app(LicenceManager::class)->install(TestLicence::signed());

        return $server;
    }

    public function reset(): void
    {
        $this->control('POST', 'reset');
    }

    /**
     * @param  array<string, mixed>  $switches
     */
    public function switch(array $switches): void
    {
        $this->control('POST', 'switches', $switches);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->control('GET', 'state');
    }

    /**
     * How many calls reached the mock with this method and path, once scoped to the company.
     */
    public function times(string $method, string $route): int
    {
        return count(array_filter($this->state()['requests'], fn (array $request): bool => $request['method'] === $method
            && $request['path'] === '/api/v1/companies/'.self::COMPANY.$route));
    }

    public function logins(): int
    {
        return count(array_filter($this->state()['requests'], fn (array $request): bool => $request['path'] === '/api/login'));
    }

    /**
     * A token, for the tests that call the mock directly, around Passorde and its guards.
     */
    public function token(): string
    {
        return (string) Http::post($this->base.'/api/login', ['public_key' => self::PUBLIC_KEY, 'secret_key' => self::SECRET])->json('access_token');
    }

    public function url(string $route): string
    {
        return $this->base.'/api/v1/companies/'.self::COMPANY.$route;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function control(string $method, string $action, array $body = []): array
    {
        $answer = file_get_contents($this->base.'/__mock/'.$action, context: stream_context_create(['http' => [
            'method' => $method,
            'header' => 'Content-Type: application/json',
            'content' => json_encode($body, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
        ]]));

        return is_string($answer) ? json_decode($answer, true, flags: JSON_THROW_ON_ERROR) : throw new RuntimeException('The mock did not answer.');
    }

    private static function start(): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('No free port for the mock.');
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $stateFile = tempnam(sys_get_temp_dir(), 'passorde-mock-') ?: throw new RuntimeException('No state file for the mock.');
        $process = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__.'/../../mock/index.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['PASSORDE_MOCK_TODAY' => self::TODAY, 'PASSORDE_MOCK_STATE' => $stateFile]),
        );

        if (! is_resource($process)) {
            throw new RuntimeException('The mock did not start.');
        }

        $server = new self($process, 'http://'.$address, $stateFile);
        register_shutdown_function($server->stop(...));

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', (int) substr((string) strrchr($address, ':'), 1), timeout: 0.1);

            if ($connection !== false) {
                fclose($connection);

                return $server;
            }

            usleep(50_000);
        }

        throw new RuntimeException('The mock did not come up on '.$address.'.');
    }

    private function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->stateFile);
        @unlink($this->stateFile.'.lock');
    }
}
