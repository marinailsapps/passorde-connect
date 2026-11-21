<?php

namespace Passorde\Writes;

use Passorde\Drafts\ContactDraft;
use Passorde\Drafts\Input;
use Passorde\Drafts\InvalidDraftException;
use Passorde\Evoliz\EvolizConnector;
use Passorde\Licence\LicenceGuard;
use Passorde\Reads\PrimaryContact;
use Passorde\Records\ContactRecord;
use Passorde\Writes\Results\Created;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Results\Updated;
use Passorde\Writes\Support\Mirror;
use Passorde\Writes\Support\Stored;
use Passorde\Writes\Support\WriteCalls;
use Passorde\Writes\Support\WriteLock;

final class UpsertContact
{
    private const CONTACTS = '/contacts-clients';

    public function __construct(
        private readonly WriteCalls $calls,
        private readonly Mirror $mirror,
        private readonly WriteLock $lock,
        private readonly LicenceGuard $licence,
    ) {}

    /**
     * Updates the contact the draft names, else the client's contact with this email, else its primary one;
     * creates the contact of a client that has none.
     *
     *
     * @return Created<ContactRecord>|Updated<ContactRecord>|Refused|Failed
     *
     * @throws InvalidDraftException When a contact must be created and the draft has no lastName. Nothing is sent.
     */
    public function run(int $clientId, ContactDraft $draft): Created|Updated|Refused|Failed
    {
        // Second line of defence: the client checks again, but a host may bind its own EvolizApi.
        $this->licence->check(EvolizConnector::ID);

        Input::id('clientId', $clientId);

        return $this->lock->around('contact:'.$clientId, function () use ($clientId, $draft): Created|Updated|Refused|Failed {
            $contacts = $this->contactsOf($clientId);
            $target = $this->target($contacts, $clientId, $draft);

            if ($draft->contactId !== null && $target === null) {
                return new Refused(null, 'Ce contact n\'appartient pas à ce client.', ['contactId' => ['Ce contact n\'appartient pas à ce client.']]);
            }

            return $target === null ? $this->create($clientId, $draft) : $this->update($clientId, $target, $draft);
        });
    }

    /**
     * @return Created<ContactRecord>|Refused|Failed
     */
    private function create(int $clientId, ContactDraft $draft): Created|Refused|Failed
    {
        if (! $draft->canCreate()) {
            throw ContactDraft::lastNameToCreate();
        }

        if ($draft->favourite === true && $draft->newEmail() === null) {
            return self::favouriteNeedsAnEmail();
        }

        $sent = $this->calls->send('contact.create', 'POST', self::CONTACTS, $draft->toCreateRequest($clientId), function () use ($clientId, $draft): ?array {
            foreach ($this->contactsOf($clientId, singleAttempt: true) as $contact) {
                if ($contact->lastName === Input::patchValue($draft->lastName) && $contact->email === self::lowered($draft->newEmail())) {
                    return $contact->raw;
                }
            }

            return null;
        });

        if ($sent->problem !== null) {
            return $sent->problem;
        }

        $record = ContactRecord::fromRow($sent->row);

        if ($record === null) {
            return new Failed(null, 'Evoliz a répondu sans identifiant de contact.', uncertain: true);
        }

        $this->mirror->clientById($clientId);

        return new Created($record);
    }

    /**
     * @return Updated<ContactRecord>|Refused|Failed
     */
    private function update(int $clientId, ContactRecord $target, ContactDraft $draft): Updated|Refused|Failed
    {
        if ($draft->clearsEmail() && $target->favourite) {
            return new Refused(null, 'L\'adresse d\'un contact favori peut être remplacée, jamais effacée.', ['email' => ['L\'adresse d\'un contact favori peut être remplacée, jamais effacée.']]);
        }

        if ($draft->favourite === true && $draft->newEmail() === null && ($draft->clearsEmail() || $target->email === null)) {
            return self::favouriteNeedsAnEmail();
        }

        $path = self::CONTACTS.'/'.$target->id;
        $body = $draft->toUpdateRequest();

        // A contact may also answer 500 without applying the change: it goes again if the read shows otherwise.
        $sent = $this->calls->send('contact.update', 'PATCH', $path, $body, function () use ($path, $body): ?array {
            $row = $this->calls->readOnce('contact.verify', $path);

            return Stored::holds($row, $body) ? $row : null;
        });

        if ($sent->problem !== null) {
            return $sent->problem;
        }

        $this->mirror->clientById($clientId);

        return new Updated(ContactRecord::fromRow($sent->row) ?? $target, $sent->recovered);
    }

    /**
     * @param  list<ContactRecord>  $contacts
     */
    private function target(array $contacts, int $clientId, ContactDraft $draft): ?ContactRecord
    {
        $email = $draft->newEmail();

        foreach ($contacts as $contact) {
            if ($contact->id === $draft->contactId) {
                return $contact;
            }
        }

        if ($draft->contactId !== null) {
            return null;
        }

        foreach ($contacts as $contact) {
            if ($contact->enabled && $email !== null && $contact->email === self::lowered($email)) {
                return $contact;
            }
        }

        return PrimaryContact::byClient($contacts)[$clientId] ?? null;
    }

    /**
     * @return list<ContactRecord>
     */
    private function contactsOf(int $clientId, bool $singleAttempt = false): array
    {
        $contacts = [];

        foreach ($this->calls->all('contact.lookup', self::CONTACTS, ['clientid' => $clientId], $singleAttempt) as $row) {
            $contact = ContactRecord::fromRow($row);

            if ($contact !== null && $contact->clientId === $clientId) {
                $contacts[] = $contact;
            }
        }

        return $contacts;
    }

    private static function lowered(?string $email): ?string
    {
        return $email === null ? null : mb_strtolower($email, 'UTF-8');
    }

    private static function favouriteNeedsAnEmail(): Refused
    {
        return new Refused(null, 'Un contact favori doit avoir une adresse.', ['email' => ['Un contact favori doit avoir une adresse.']]);
    }
}
