<?php

namespace Passorde\Mock;

use Closure;
use RuntimeException;

/**
 * The state lives in one JSON file, locked for the length of a request.
 */
final readonly class Store
{
    public function __construct(private string $file) {}

    /**
     * @param  Closure(array<string, mixed>): array{0: array<string, mixed>, 1: Answer}  $work
     */
    public function transaction(Closure $work): Answer
    {
        $lock = fopen($this->file.'.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock '.$this->file);
        }

        try {
            [$state, $answer] = $work($this->load());
            file_put_contents($this->file, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $answer;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $saved = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

        return is_array($saved) ? $saved : Seed::build(Seed::today());
    }
}
