<?php

namespace Passorde\Writes\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Passorde\Reads\PrimaryContact;
use Passorde\Records\ArticleRecord;
use Passorde\Records\ClientRecord;
use Passorde\Records\ContactRecord;
use Passorde\Records\DeliveryRecord;
use Passorde\Sinks\Contracts\ArticleSink;
use Passorde\Sinks\Contracts\ClientSink;
use Passorde\Sinks\Contracts\DeliverySink;
use Throwable;

/**
 * Hands a written record to the sinks, so that the next read is consistent. The write has happened by then: a sink
 * that fails gets a warning and never fails the verb, whose caller would send it again. The next sync catches up.
 *
 * With passorde-connect.writes.mirror off, nothing reaches the sinks and nothing is read for them: no contacts after a
 * client write, no client after a contact write. The sinks then wait for the next sync.
 */
final class Mirror
{
    public function __construct(
        private readonly WriteCalls $calls,
        private readonly ArticleSink $articles,
        private readonly ClientSink $clients,
        private readonly DeliverySink $deliveries,
    ) {}

    public function article(ArticleRecord $record): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->safely('article', fn () => $this->articles->upsert([$record], CarbonImmutable::now()));
    }

    public function delivery(DeliveryRecord $record): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->safely('delivery', fn () => $this->deliveries->upsert([$record], CarbonImmutable::now()));
    }

    /**
     * A client created this instant, who has no contact yet.
     */
    public function newClient(ClientRecord $record): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->safely('client', fn () => $this->clients->upsert([$record], CarbonImmutable::now()));
    }

    /**
     * A client row of Evoliz, with its primary contact. A sink takes a client without one to mean the contact is
     * gone, so if the contacts cannot be read the sink is left alone. With the mirror off, the record comes from the
     * row alone, without its primary contact.
     *
     * @param  array<array-key, mixed>  $row
     */
    public function client(array $row): ?ClientRecord
    {
        if (! self::enabled()) {
            return ClientRecord::fromRow($row);
        }

        $id = (int) ($row['clientid'] ?? 0);

        try {
            $contacts = array_filter(
                array_map(ContactRecord::fromRow(...), $this->calls->all('contact.read', '/contacts-clients', ['clientid' => $id], singleAttempt: true)),
                fn (?ContactRecord $contact): bool => $contact !== null && $contact->clientId === $id,
            );
        } catch (Throwable $exception) {
            $this->warn('client', $exception);

            return ClientRecord::fromRow($row);
        }

        $record = ClientRecord::fromRow($row, PrimaryContact::byClient($contacts)[$id] ?? null);

        if ($record !== null) {
            $this->safely('client', fn () => $this->clients->upsert([$record], CarbonImmutable::now()));
        }

        return $record;
    }

    /**
     * The client as Evoliz holds it now, after a change to one of its contacts.
     */
    public function clientById(int $clientId): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            $row = $this->calls->readOnce('client.read', '/clients/'.$clientId);
        } catch (Throwable $exception) {
            $this->warn('client', $exception);

            return;
        }

        $this->client($row);
    }

    /**
     * On unless passorde-connect.writes.mirror says false; a config published before the key existed keeps it on.
     */
    public static function enabled(): bool
    {
        $value = config('passorde-connect.writes.mirror');

        return $value === null || filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * @param  Closure(): mixed  $write
     */
    private function safely(string $what, Closure $write): void
    {
        try {
            $write();
        } catch (Throwable $exception) {
            $this->warn($what, $exception);
        }
    }

    private function warn(string $what, Throwable $exception): void
    {
        Log::warning('Passorde: le miroir n\'a pas pu être mis à jour après une écriture réussie.', ['record' => $what, 'error' => $exception->getMessage()]);
    }
}
