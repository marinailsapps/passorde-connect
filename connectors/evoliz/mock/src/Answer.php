<?php

namespace Passorde\Mock;

final readonly class Answer
{
    /**
     * @param  array<array-key, mixed>  $body
     * @param  array<string, int|string>  $headers
     */
    public function __construct(
        public int $status,
        public array $body,
        public array $headers = [],
    ) {}

    /**
     * @param  array<string, int|string>  $headers
     */
    public function with(array $headers): self
    {
        return new self($this->status, $this->body, $headers + $this->headers);
    }
}
