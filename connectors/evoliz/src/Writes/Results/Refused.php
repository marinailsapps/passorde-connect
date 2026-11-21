<?php

namespace Passorde\Writes\Results;

use Passorde\Api\ApiError;

/**
 * The request is the problem: sending it again would change nothing, and nothing was written.
 */
final readonly class Refused implements WriteResult
{
    /**
     * @param  int|null  $status  Null when Passorde refused it itself, knowing how Evoliz would treat it.
     * @param  array<string, list<string>>  $fieldErrors  The validation messages, by field.
     */
    public function __construct(public ?int $status, public string $message, public array $fieldErrors = []) {}

    public static function fromError(ApiError $error): self
    {
        return new self($error->status, $error->message, $error->fieldErrors);
    }

    public function succeeded(): bool
    {
        return false;
    }
}
