<?php

namespace Passorde\Api;

/**
 * Patterns compare whole path segments, anywhere in the path and whatever the case;
 * a segment written * or {name} stands for any one segment.
 */
final class ForbiddenPaths
{
    /**
     * the Evoliz connector does not invoice: configuration can add to these, never remove them.
     */
    public const ALWAYS = ['/invoices', '/deliveries/{id}/invoice', '/invoice', '/progress-invoice', '/credits', '/recoveries'];

    /**
     * Passorde creates deliveries and nothing else: once registered, one is never updated, deleted or sent.
     */
    private const DELIVERY_BEYOND_CREATE = '/deliveries/{id}';

    /**
     * @param  list<string>  $patterns
     */
    public function __construct(private readonly array $patterns) {}

    public static function fromConfig(): self
    {
        $patterns = config('passorde-connect.evoliz.forbidden_paths');

        return new self(array_values(array_unique(array_merge(self::ALWAYS, array_filter(
            is_array($patterns) ? $patterns : [],
            fn (mixed $pattern): bool => is_string($pattern) && trim($pattern, " \t/") !== '',
        )))));
    }

    /**
     * @return string|null The pattern that forbids this method on this path, if any.
     */
    public function matchCall(string $method, ApiPath $path): ?string
    {
        $pattern = $this->match($path);

        if ($pattern !== null || strtoupper($method) === 'GET') {
            return $pattern;
        }

        return (new self([self::DELIVERY_BEYOND_CREATE]))->match($path);
    }

    /**
     * @return string|null The pattern that forbids this path, if any.
     */
    public function match(ApiPath $path): ?string
    {
        foreach ($this->patterns as $pattern) {
            $wanted = array_values(array_filter(explode('/', strtolower(trim($pattern))), fn (string $s): bool => $s !== ''));

            for ($start = 0; $start <= count($path->segments) - count($wanted); $start++) {
                if ($this->matchesAt($path->segments, $wanted, $start)) {
                    return $pattern;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $segments
     * @param  list<string>  $wanted
     */
    private function matchesAt(array $segments, array $wanted, int $start): bool
    {
        foreach ($wanted as $offset => $segment) {
            $isWildcard = $segment === '*' || (str_starts_with($segment, '{') && str_ends_with($segment, '}'));

            if (! $isWildcard && $segments[$start + $offset] !== $segment) {
                return false;
            }
        }

        return true;
    }
}
