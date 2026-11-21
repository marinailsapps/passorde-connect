<?php

namespace Passorde\Writes;

use Carbon\CarbonImmutable;
use Passorde\Drafts\DeliveryDraft;
use Passorde\Evoliz\EvolizConnector;
use Passorde\Licence\LicenceGuard;
use Passorde\Records\DeliveryRecord;
use Passorde\Records\Row;
use Passorde\Writes\Results\AlreadyExists;
use Passorde\Writes\Results\Created;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Support\Mirror;
use Passorde\Writes\Support\WriteCalls;
use Passorde\Writes\Support\WriteLock;

/**
 * Creating is all Passorde does to a delivery. Evoliz registers it at once, with a definitive number, and its API
 * cannot delete it: hence the lookup before anything is sent, and again before anything is sent twice.
 */
final class CreateDelivery
{
    private const DEFAULT_DAYS_BACK = 366;

    private const DEFAULT_DAYS_AHEAD = 31;

    public function __construct(
        private readonly WriteCalls $calls,
        private readonly Mirror $mirror,
        private readonly WriteLock $lock,
        private readonly LicenceGuard $licence,
    ) {}

    /**
     * @return Created<DeliveryRecord>|AlreadyExists<DeliveryRecord>|Refused|Failed
     */
    public function run(DeliveryDraft $draft): Created|AlreadyExists|Refused|Failed
    {
        // Second line of defence: the client checks again, but a host may bind its own EvolizApi.
        $this->licence->check(EvolizConnector::ID);

        $key = 'delivery:'.$draft->clientId.':'.hash('sha256', trim($draft->externalNumber));

        return $this->lock->around($key, function () use ($draft): Created|AlreadyExists|Refused|Failed {
            $existing = $this->find($draft);

            if ($existing !== null) {
                return $this->exists($existing, false);
            }

            $sent = $this->calls->send('delivery.create', 'POST', '/deliveries', $draft->toRequest(), fn (): ?array => $this->find($draft, singleAttempt: true));

            if ($sent->problem !== null) {
                return $sent->problem;
            }

            if ($sent->recovered) {
                return $this->exists($sent->row, true);
            }

            $record = DeliveryRecord::fromRow($sent->row);

            if ($record === null) {
                return self::unreadable();
            }

            $this->mirror->delivery($record);

            return new Created($record);
        });
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return AlreadyExists<DeliveryRecord>|Failed
     */
    private function exists(array $row, bool $afterUncertainResponse): AlreadyExists|Failed
    {
        $record = DeliveryRecord::fromRow($row);

        if ($record === null) {
            return self::unreadable();
        }

        $this->mirror->delivery($record);

        return new AlreadyExists($record, $afterUncertainResponse);
    }

    /**
     * Evoliz's search filter does not work, so this reads the client's deliveries of a period and compares the
     * number exactly. The period is wide: the document date of a delivery can be edited in Evoliz after the fact,
     * and the same call can come back long after, with a later date.
     *
     * @return array<array-key, mixed>|null
     */
    private function find(DeliveryDraft $draft, bool $singleAttempt = false): ?array
    {
        $number = self::comparable($draft->externalNumber);

        return $this->calls->first('delivery.lookup', '/deliveries', [
            'clientid' => $draft->clientId,
            'period' => 'custom',
            ...self::window($draft),
        ], function (array $row) use ($draft, $number): bool {
            $client = Row::id(Row::nested($row, 'client'), 'clientid');

            return self::comparable($row['external_document_number'] ?? null) === $number
                && ($client === null || $client === $draft->clientId);
        }, $singleAttempt);
    }

    /**
     * @return array{date_min: string, date_max: string}
     */
    private static function window(DeliveryDraft $draft): array
    {
        $date = CarbonImmutable::parse($draft->documentDate);
        $today = CarbonImmutable::now();

        return [
            'date_min' => $date->min($today)->subDays(self::days('days_back', self::DEFAULT_DAYS_BACK))->format('Y-m-d'),
            'date_max' => $date->max($today)->addDays(self::days('days_ahead', self::DEFAULT_DAYS_AHEAD))->format('Y-m-d'),
        ];
    }

    private static function days(string $key, int $default): int
    {
        $value = config('passorde-connect.writes.delivery_lookup.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function comparable(mixed $number): ?string
    {
        return is_scalar($number) ? html_entity_decode(trim((string) $number), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
    }

    private static function unreadable(): Failed
    {
        return new Failed(null, 'Evoliz a répondu sans identifiant de bon de livraison.', uncertain: true);
    }
}
