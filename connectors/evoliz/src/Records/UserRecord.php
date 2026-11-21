<?php

namespace Passorde\Records;

final readonly class UserRecord implements SyncedRecord
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public int $id,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $fullName,
        public ?string $email,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $id = Row::id($row, 'userid');

        if ($id === null) {
            return null;
        }

        return new self(
            id: $id,
            firstName: Text::clean($row['firstname'] ?? null),
            lastName: Text::clean($row['lastname'] ?? null),
            fullName: Text::join([$row['firstname'] ?? null, $row['lastname'] ?? null], ' '),
            email: Text::email($row['email'] ?? null),
            raw: $row,
        );
    }

    public function evolizId(): int
    {
        return $this->id;
    }
}
