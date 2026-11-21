<?php

namespace Passorde\Reads;

use Passorde\Records\ContactRecord;

final class PrimaryContact
{
    /**
     * The contact that speaks for each client: the first enabled one, favourites first.
     *
     * @param  iterable<ContactRecord>  $contacts
     * @return array<int, ContactRecord> By client id.
     */
    public static function byClient(iterable $contacts): array
    {
        $primary = [];

        foreach ($contacts as $contact) {
            if (! $contact->enabled || $contact->clientId === null) {
                continue;
            }

            $current = $primary[$contact->clientId] ?? null;

            if ($current === null || ($contact->favourite && ! $current->favourite)) {
                $primary[$contact->clientId] = $contact;
            }
        }

        return $primary;
    }
}
