<?php

namespace Passorde\Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * A fictional Evoliz whose listings a test may change between two runs. Pages and dates are served as the real one does.
 */
final class FakeListings
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $rows = [];

    /** @var array<string, int> */
    public array $refused = [];

    public function __construct()
    {
        FakeEvoliz::configure();
        FakeEvoliz::fake([FakeEvoliz::SCOPE.'/*' => fn (Request $request) => $this->answer($request)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fixtureRows(string $name): array
    {
        return FakeEvoliz::fixture($name)['data'];
    }

    /**
     * @return int Calls to Evoliz other than the login.
     */
    public static function callsTo(string $endpoint): int
    {
        return count(array_filter(FakeEvoliz::calls(), fn (Request $request) => str_contains($request->url(), '/'.$endpoint)));
    }

    public static function migrate(): void
    {
        (require __DIR__.'/../../database/migrations/2026_01_01_000001_create_passorde_runs_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000002_create_passorde_mirror_tables.php')->up();
    }

    public static function lastRun(string $kind): object
    {
        return DB::table('passorde_runs')->where('kind', $kind)->orderByDesc('id')->first();
    }

    private function answer(Request $request)
    {
        $endpoint = trim(explode('?', substr($request->url(), strlen(FakeEvoliz::BASE.'/api/v1/companies/12345')))[0], '/');

        if (isset($this->refused[$endpoint])) {
            return Http::response(['error' => 'Refused'], $this->refused[$endpoint]);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $rows = array_values(array_filter($this->rows[$endpoint] ?? [], fn (array $row) => ! isset($query['date_min'])
            || (($row['documentdate'] ?? '') >= $query['date_min'] && ($row['documentdate'] ?? '') <= $query['date_max'])));
        $perPage = (int) ($query['per_page'] ?? 100);
        $page = (int) ($query['page'] ?? 1);

        return Http::response([
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil(count($rows) / $perPage)), 'total' => count($rows)],
        ]);
    }
}
