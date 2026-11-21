<?php

namespace Passorde\Tests\Support;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Passorde\Api\Contracts\RequestLog;
use RuntimeException;
use Throwable;

/**
 * A fictional Evoliz that answers from a script, per method and path, and fails on any call the script did not expect.
 */
final class WriteFake
{
    /** @var list<Request> Every call that reached the script, including those answered by a network failure. */
    private static array $requests = [];

    /**
     * @param  array<string, list<mixed>>  $queues  'POST /clients' => answers, in order.
     */
    private function __construct(private array $queues) {}

    /**
     * @param  array<string, list<mixed>>  $script
     */
    public static function start(array $script, bool $writes = true): self
    {
        FakeEvoliz::configure(writes: $writes);
        config()->set('passorde-connect.request_log.driver', 'database');
        app()->forgetInstance(RequestLog::class);

        (require __DIR__.'/../../../../database/migrations/2026_01_01_000000_create_passorde_requests_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000002_create_passorde_mirror_tables.php')->up();

        Sleep::fake();
        self::$requests = [];

        $fake = new self($script);
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/*' => fn (Request $request) => $fake->answer($request)]);

        return $fake;
    }

    public static function fixture(string $name): array
    {
        return FakeEvoliz::fixture('write/'.$name);
    }

    public static function ok(array $body, int $status = 200): mixed
    {
        return Http::response($body, $status);
    }

    public static function json(string $fixture, int $status = 200): mixed
    {
        return Http::response(self::fixture($fixture), $status);
    }

    public static function page(array $rows = [], int $lastPage = 1): mixed
    {
        return Http::response(['data' => $rows, 'meta' => ['last_page' => $lastPage]]);
    }

    public static function serverError(): mixed
    {
        return Http::response(['message' => 'Server Error'], 500);
    }

    public static function networkFailure(): Throwable
    {
        return new ConnectionException('cURL error 28: Operation timed out');
    }

    /**
     * @return list<string> Every call made, as 'POST /clients?query', logins left out.
     */
    public static function trail(): array
    {
        return array_map(function (Request $request): string {
            $path = substr(urldecode($request->url()), strlen(FakeEvoliz::BASE.'/api/v1/companies/'.FakeEvoliz::COMPANY));

            return $request->method().' '.$path;
        }, self::$requests);
    }

    /**
     * @return array<array-key, mixed> The decoded JSON of the n-th call with this method and path.
     */
    public static function sent(string $call, int $nth = 0): array
    {
        $matching = array_values(array_filter(self::$requests, fn (Request $request): bool => self::key($request) === $call));

        return json_decode($matching[$nth]->body(), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function times(string $call): int
    {
        return count(array_filter(self::$requests, fn (Request $request): bool => self::key($request) === $call));
    }

    /**
     * @return list<object> The rows of the request log, oldest first.
     */
    public static function logged(?string $kind = null): array
    {
        return DB::table('passorde_requests')->when($kind, fn ($query) => $query->where('kind', $kind))->orderBy('id')->get()->all();
    }

    private static function key(Request $request): string
    {
        return $request->method().' '.substr(explode('?', $request->url())[0], strlen(FakeEvoliz::BASE.'/api/v1/companies/'.FakeEvoliz::COMPANY));
    }

    private function answer(Request $request): mixed
    {
        self::$requests[] = $request;
        $key = self::key($request);

        if (($this->queues[$key] ?? []) === []) {
            throw new RuntimeException('Appel imprévu : '.$key);
        }

        $answer = array_shift($this->queues[$key]);

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer instanceof Closure ? $answer($request) : $answer;
    }
}
