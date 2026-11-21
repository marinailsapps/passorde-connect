<?php

use Passorde\Licence\Exceptions\LicenceStoreException;
use Passorde\Licence\FileLicenceStore;

function mode(string $path): int
{
    clearstatcache();

    return fileperms($path) & 0777;
}

it('returns null when nothing is installed', function () {
    $store = new FileLicenceStore($this->licencePath);

    expect($store->current())->toBeNull()
        ->and($store->previous())->toBeNull()
        ->and($store->lastRefreshAt())->toBeNull();
});

it('creates the file with mode 600', function () {
    (new FileLicenceStore($this->licencePath))->replace('one');

    expect(file_get_contents($this->licencePath))->toBe('one')
        ->and(mode($this->licencePath))->toBe(0600);
});

it('keeps the previous file once, with mode 600', function () {
    $store = new FileLicenceStore($this->licencePath);
    $store->replace('one');
    $store->replace('two');
    $store->replace('three');

    $previous = $this->licenceDirectory.'/licence.previous.key';

    expect($store->current())->toBe('three')
        ->and($store->previous())->toBe('two')
        ->and(mode($previous))->toBe(0600)
        ->and(glob($this->licenceDirectory.'/*'))->toHaveCount(2);
});

it('leaves no temporary file behind', function () {
    (new FileLicenceStore($this->licencePath))->replace('one');

    expect(scandir($this->licenceDirectory))->toBe(['.', '..', 'licence.key']);
});

it('remembers the last refresh in a file with mode 600', function () {
    $store = new FileLicenceStore($this->licencePath);
    $store->markRefreshed(new DateTimeImmutable('2026-06-15T12:00:00+02:00'));

    expect($store->lastRefreshAt()->format(DATE_ATOM))->toBe('2026-06-15T12:00:00+02:00')
        ->and(mode($this->licenceDirectory.'/licence.meta.json'))->toBe(0600);
});

it('throws and keeps the current file when the folder cannot be written', function () {
    $store = new FileLicenceStore($this->licencePath);
    $store->replace('one');
    chmod($this->licenceDirectory, 0500);

    try {
        expect(fn () => $store->replace('two'))->toThrow(LicenceStoreException::class)
            ->and($store->current())->toBe('one');
    } finally {
        chmod($this->licenceDirectory, 0700);
    }
});
