<?php

namespace Passorde\Drafts;

use InvalidArgumentException;

/**
 * A draft that could not make a valid request. Nothing was sent.
 */
final class InvalidDraftException extends InvalidArgumentException
{
    /**
     * @param  array<string, string>  $fieldErrors
     */
    public function __construct(public readonly array $fieldErrors)
    {
        parent::__construct(implode(' ; ', array_map(
            fn (string $field, string $message): string => $field.' : '.$message,
            array_keys($fieldErrors),
            $fieldErrors,
        )));
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => $message]);
    }
}
