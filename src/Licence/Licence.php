<?php

namespace Passorde\Licence;

use stdClass;

/**
 * The fields of a licence whose signature has been verified.
 */
final readonly class Licence
{
    /**
     * @param  non-empty-list<non-empty-string>  $connectors  The connector ids the licence covers.
     */
    public function __construct(
        public string $kid,
        public string $licenceId,
        public ?string $licenceToken,
        public string $product,
        public array $connectors,
        public string $plan,
        public ?string $email,
        public ?string $company,
        public ?string $siret,
        public string $issuedAt,
        public ?string $expiresAt,
        public string $versionMax,
        public ?string $updatesUntil = null,
    ) {}

    public function covers(string $connector): bool
    {
        return in_array($connector, $this->connectors, true);
    }

    /**
     * Expects the required fields to have been type-checked already, connectors included.
     */
    public static function fromVerified(stdClass $fields): self
    {
        $optional = fn (string $name): ?string => is_string($fields->{$name} ?? null) ? $fields->{$name} : null;

        return new self(
            kid: $fields->kid,
            licenceId: $fields->licence_id,
            licenceToken: $optional('licence_token'),
            product: $fields->product,
            connectors: $fields->connectors,
            plan: $fields->plan,
            email: $optional('email'),
            company: $optional('company'),
            siret: $optional('siret'),
            issuedAt: $fields->issued_at,
            expiresAt: $fields->expires_at,
            versionMax: $fields->version_max,
            updatesUntil: $optional('updates_until'),
        );
    }
}
