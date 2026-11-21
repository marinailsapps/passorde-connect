<?php

namespace Passorde\Licence;

use DateTimeImmutable;
use Exception;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\Exceptions\LicenceStoreException;

/**
 * The licence key in a file, one line, by default storage/passorde/licence.key; the key it replaced beside it, as
 * licence.previous.key. The file is the host's to protect: it holds the refresh token.
 */
final class FileLicenceStore implements LicenceStore
{
    private const PREVIOUS_FILE = 'licence.previous.key';

    private const META_FILE = 'licence.meta.json';

    public function __construct(private readonly string $path) {}

    public function current(): ?string
    {
        return $this->read($this->path);
    }

    public function previous(): ?string
    {
        return $this->read($this->sibling(self::PREVIOUS_FILE));
    }

    public function replace(string $raw): void
    {
        $current = $this->current();

        if ($current !== null) {
            $this->writeAtomically($this->sibling(self::PREVIOUS_FILE), $current);
        }

        $this->writeAtomically($this->path, $raw);
    }

    public function lastRefreshAt(): ?DateTimeImmutable
    {
        $meta = json_decode((string) $this->read($this->sibling(self::META_FILE)), true);

        if (! is_array($meta) || ! is_string($meta['last_refresh_at'] ?? null)) {
            return null;
        }

        try {
            return new DateTimeImmutable($meta['last_refresh_at']);
        } catch (Exception) {
            return null;
        }
    }

    public function markRefreshed(DateTimeImmutable $at): void
    {
        $this->writeAtomically(
            $this->sibling(self::META_FILE),
            (string) json_encode(['last_refresh_at' => $at->format(DATE_ATOM)]),
        );
    }

    private function read(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    private function sibling(string $name): string
    {
        return dirname($this->path).DIRECTORY_SEPARATOR.$name;
    }

    /**
     * Writes to a temporary file in the same folder, then renames it, so that a failure at any
     * point leaves the existing file intact and readers never see a partial licence.
     */
    private function writeAtomically(string $path, string $content): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new LicenceStoreException("Cannot create the folder {$directory}.");
        }

        $temporary = @tempnam($directory, '.licence-');

        // tempnam() silently falls back to the system temporary folder when this one is not writable.
        if ($temporary === false || dirname($temporary) !== (realpath($directory) ?: $directory)) {
            if ($temporary !== false) {
                @unlink($temporary);
            }

            throw new LicenceStoreException("Cannot write in the folder {$directory}.");
        }

        if (
            @chmod($temporary, 0600) === false
            || @file_put_contents($temporary, $content) !== strlen($content)
            || @rename($temporary, $path) === false
        ) {
            @unlink($temporary);

            throw new LicenceStoreException("Cannot write the file {$path}.");
        }
    }
}
