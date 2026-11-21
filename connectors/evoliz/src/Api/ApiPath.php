<?php

namespace Passorde\Api;

use Passorde\Api\Exceptions\InvalidPathException;

/**
 * A path as given by the caller: never a URL, so the token can only ever go to the configured host.
 */
final readonly class ApiPath
{
    /**
     * @param  array<array-key, mixed>  $query
     * @param  list<string>  $segments  Decoded and lower-cased, for comparisons only.
     */
    private function __construct(
        public string $path,
        public array $query,
        public array $segments,
    ) {}

    /**
     * @throws InvalidPathException
     */
    public static function parse(string $raw): self
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || str_contains($trimmed, '://') || str_contains($trimmed, '\\') || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            throw new InvalidPathException($raw);
        }

        $withoutFragment = explode('#', $trimmed, 2)[0];
        [$path, $queryString] = array_pad(explode('?', $withoutFragment, 2), 2, '');
        parse_str($queryString, $query);

        $segments = self::segments($path);

        // A dot segment could climb out of the company scope, or hide a forbidden path.
        if (array_intersect($segments, ['.', '..']) !== []) {
            throw new InvalidPathException($raw);
        }

        return new self('/'.ltrim($path, '/'), $query, $segments);
    }

    public function isAbsolute(): bool
    {
        return ($this->segments[0] ?? null) === 'api' && str_starts_with(strtolower($this->path), '/api');
    }

    public function scopedTo(string $companyId): string
    {
        return $this->isAbsolute()
            ? $this->path
            : '/api/v1/companies/'.rawurlencode($companyId).$this->path;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        // Decoded until stable, so that %2569nvoices cannot pass for something other than invoices.
        do {
            $previous = $path;
            $path = rawurldecode($path);
        } while ($path !== $previous);

        $segments = array_map(
            fn (string $segment): string => strtolower(trim($segment)),
            explode('/', str_replace('\\', '/', $path)),
        );

        return array_values(array_filter($segments, fn (string $segment): bool => $segment !== ''));
    }
}
