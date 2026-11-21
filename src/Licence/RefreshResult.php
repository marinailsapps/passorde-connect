<?php

namespace Passorde\Licence;

final readonly class RefreshResult
{
    /**
     * @param  bool  $skipped  True when there was nothing to refresh: no licence installed, a lifetime licence or one
     *                         without a token. Not a failure; $refreshed is false.
     */
    public function __construct(
        public bool $refreshed,
        public string $message,
        public bool $skipped = false,
    ) {}

    public static function skipped(string $message): self
    {
        return new self(false, $message, skipped: true);
    }

    public function failed(): bool
    {
        return ! $this->refreshed && ! $this->skipped;
    }
}
