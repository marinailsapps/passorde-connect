<?php

namespace Passorde\Drafts;

final readonly class ContactDraft
{
    /**
     * @param  string|null  $lastName  Needed to create a contact. Null leaves it alone on an existing one, so an email-only correction needs no name.
     * @param  string|null  $firstName  Null leaves it alone on an existing contact; an empty text clears it.
     * @param  string|null  $email  Null leaves it alone on an existing contact; an empty text clears it, which Evoliz does not allow on a favourite contact.
     * @param  bool|null  $favourite  Null leaves it alone. A contact's first email makes it the favourite of a client with no other contact.
     * @param  int|null  $contactId  The contact to update. Without one: the client's contact with this email, otherwise its primary one.
     */
    public function __construct(
        public ?string $lastName = null,
        public ?string $firstName = null,
        public ?string $email = null,
        public ?bool $favourite = null,
        public ?int $contactId = null,
    ) {
        if ($lastName !== null) {
            Input::required('lastName', $lastName);
        }

        if ($lastName === null && $firstName === null && $email === null && $favourite === null) {
            throw InvalidDraftException::field('contact', 'aucun champ à écrire');
        }

        if ($contactId !== null) {
            Input::id('contactId', $contactId);
        }
    }

    public function clearsEmail(): bool
    {
        return $this->email !== null && trim($this->email) === '';
    }

    public function newEmail(): ?string
    {
        return Input::patchValue($this->email);
    }

    public function canCreate(): bool
    {
        return $this->lastName !== null;
    }

    /**
     * POST /contacts-clients. A contact cannot be favourite without an email.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidDraftException When the draft has no lastName, which Evoliz requires to create a contact.
     */
    public function toCreateRequest(int $clientId): array
    {
        if ($this->lastName === null) {
            throw self::lastNameToCreate();
        }

        $email = $this->newEmail();
        $body = ['clientid' => $clientId, 'lastname' => trim($this->lastName)];

        if (Input::patchValue($this->firstName) !== null) {
            $body['firstname'] = Input::patchValue($this->firstName);
        }

        if ($email !== null) {
            $body['email'] = $email;
            $body['favorite'] = $this->favourite ?? true;
        }

        return $body;
    }

    /**
     * PATCH /contacts-clients/{id}.
     *
     * @return array<string, mixed>
     */
    public function toUpdateRequest(): array
    {
        $body = $this->lastName === null ? [] : ['lastname' => trim($this->lastName)];

        if ($this->firstName !== null) {
            $body['firstname'] = Input::patchValue($this->firstName);
        }

        if ($this->email !== null) {
            $body['email'] = $this->newEmail();
        }

        if ($this->favourite !== null) {
            $body['favorite'] = $this->favourite;
        }

        return $body;
    }

    public static function lastNameToCreate(): InvalidDraftException
    {
        return InvalidDraftException::field('lastName', 'obligatoire pour créer un contact : ce client n\'en a aucun à modifier');
    }
}
