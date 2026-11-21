<?php

namespace Passorde\Reads;

use Generator;
use Passorde\Records\ClientRecord;
use Passorde\Records\ContactRecord;
use Passorde\Records\Row;

final class ReadClients
{
    public function __construct(private readonly Listing $listing) {}

    /**
     * Each client comes with its primary contact: the first enabled one, favourites first.
     *
     * @return Generator<int, ClientRecord>
     */
    public function all(): Generator
    {
        $contacts = $this->primaryContacts();

        yield from $this->clients($contacts);
    }

    /**
     * @param  array<int, ContactRecord>  $contacts
     * @return Generator<int, ClientRecord>
     */
    private function clients(array $contacts): Generator
    {
        return $this->listing->records('/clients', [], fn (array $row): ?ClientRecord => ClientRecord::fromRow(
            $row,
            $contacts[(int) Row::id($row, 'clientid')] ?? null,
        ));
    }

    /**
     * @return array<int, ContactRecord> By client id.
     */
    private function primaryContacts(): array
    {
        return PrimaryContact::byClient($this->listing->records('/contacts-clients', [], ContactRecord::fromRow(...)));
    }
}
