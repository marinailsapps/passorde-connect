<?php

namespace Passorde\Licence;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\Exceptions\LicenceStoreException;
use stdClass;

/**
 * The licence key in the passorde_licences table, encrypted with the application's own key (APP_KEY, through
 * Laravel's encrypter, so APP_PREVIOUS_KEYS also decrypts). Two rows: current and previous. A database backup
 * then holds the key only in encrypted form; losing APP_KEY means installing the key again.
 */
final class DatabaseLicenceStore implements LicenceStore
{
    public const TABLE = 'passorde_licences';

    private const CURRENT = 'current';

    private const PREVIOUS = 'previous';

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly StringEncrypter $encrypter,
        private readonly ?string $connection = null,
    ) {}

    public function current(): ?string
    {
        return $this->decrypt($this->row(self::CURRENT));
    }

    public function previous(): ?string
    {
        return $this->decrypt($this->row(self::PREVIOUS));
    }

    public function replace(string $raw): void
    {
        $this->attempt(function () use ($raw): void {
            $this->connection()->transaction(function () use ($raw): void {
                $current = $this->row(self::CURRENT, lock: true);
                $now = CarbonImmutable::now();

                if ($current !== null && $current->payload !== null) {
                    $this->table()->updateOrInsert(['slot' => self::PREVIOUS], [
                        'payload' => $current->payload,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                // The refresh date stays with the current row: a key installed by hand does not reset it.
                $this->table()->updateOrInsert(['slot' => self::CURRENT], [
                    'payload' => $this->encrypter->encryptString($raw),
                    'created_at' => $current->created_at ?? $now,
                    'updated_at' => $now,
                ]);
            });
        });
    }

    public function lastRefreshAt(): ?DateTimeImmutable
    {
        $value = $this->row(self::CURRENT)?->refreshed_at;

        // Written in UTC by markRefreshed(), whatever the application's timezone.
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC')->toDateTimeImmutable() : null;
    }

    public function markRefreshed(DateTimeImmutable $at): void
    {
        $this->attempt(fn () => $this->table()->where('slot', self::CURRENT)->update([
            'refreshed_at' => CarbonImmutable::instance($at)->utc()->format('Y-m-d H:i:s'),
            'updated_at' => CarbonImmutable::now(),
        ]));
    }

    private function row(string $slot, bool $lock = false): ?stdClass
    {
        return $this->attempt(function () use ($slot, $lock): ?stdClass {
            $query = $this->table()->where('slot', $slot);
            $row = ($lock ? $query->lockForUpdate() : $query)->first();

            return $row instanceof stdClass ? $row : null;
        });
    }

    private function decrypt(?stdClass $row): ?string
    {
        if ($row === null || ! is_string($row->payload) || $row->payload === '') {
            return null;
        }

        try {
            return $this->encrypter->decryptString($row->payload);
        } catch (DecryptException) {
            throw new LicenceStoreException("The stored licence key cannot be decrypted with this application's key (APP_KEY): install it again.");
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws LicenceStoreException
     */
    private function attempt(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $exception) {
            throw new LicenceStoreException('The table '.self::TABLE.' cannot be read or written: run the migrations.', previous: $exception);
        }
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }

    private function table(): Builder
    {
        return $this->connection()->table(self::TABLE);
    }
}
