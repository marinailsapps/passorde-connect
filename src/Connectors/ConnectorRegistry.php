<?php

namespace Passorde\Connectors;

use InvalidArgumentException;

/**
 * The connectors this installation provides. Each connector module registers itself from its service provider; the
 * licence covers a connector only when its id is in the licence's signed connectors list.
 *
 * The first connector registered is the default one: the licence status that the page, the commands and the host
 * see without naming a connector is the status for that one.
 */
final class ConnectorRegistry
{
    /** Lower-case letters, digits and hyphens, starting with a letter: the form used in the licence. */
    public const ID_PATTERN = '/^[a-z][a-z0-9-]{0,62}\z/';

    /** @var array<string, array{name: string, notice: ?string}> */
    private array $connectors = [];

    /**
     * @param  string  $id  The id that a licence lists in connectors, such as the one the module defines.
     * @param  string  $name  Its name for people.
     * @param  string|null  $notice  A line the licence page shows, such as a non-affiliation statement.
     */
    public function register(string $id, string $name, ?string $notice = null): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException("Invalid connector id \"{$id}\".");
        }

        $this->connectors[$id] = ['name' => $name, 'notice' => $notice];
    }

    public function has(string $id): bool
    {
        return isset($this->connectors[$id]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->connectors);
    }

    public function default(): ?string
    {
        return array_key_first($this->connectors);
    }

    /**
     * The name of a registered connector, or the id itself for one this installation does not provide.
     */
    public function name(string $id): string
    {
        return $this->connectors[$id]['name'] ?? $id;
    }

    /**
     * The notice of a registered connector, or null.
     */
    public function notice(string $id): ?string
    {
        return $this->connectors[$id]['notice'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function notices(): array
    {
        return array_values(array_filter(array_column($this->connectors, 'notice')));
    }
}
