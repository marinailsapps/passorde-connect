<?php

namespace Passorde\Records;

final readonly class ContactRecord implements SyncedRecord
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public int $id,
        public ?int $clientId,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $fullName,
        public ?string $email,
        public bool $favourite,
        public bool $enabled,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $id = Row::id($row, 'contactid');

        if ($id === null) {
            return null;
        }

        return new self(
            id: $id,
            clientId: Row::id(Row::nested($row, 'client'), 'clientid'),
            firstName: Text::clean($row['firstname'] ?? null),
            lastName: Text::clean($row['lastname'] ?? null),
            fullName: Text::join([$row['firstname'] ?? null, $row['lastname'] ?? null], ' '),
            email: Text::email($row['email'] ?? null),
            favourite: Row::bool($row, 'favorite', false),
            enabled: Row::bool($row, 'enabled', true),
            raw: $row,
        );
    }

    public function evolizId(): int
    {
        return $this->id;
    }
}
