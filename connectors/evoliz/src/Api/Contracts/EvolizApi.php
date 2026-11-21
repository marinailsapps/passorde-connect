<?php

namespace Passorde\Api\Contracts;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Passorde\Api\Exceptions\ApiConfigurationException;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\ForbiddenPathException;
use Passorde\Api\Exceptions\InvalidPathException;
use Passorde\Api\Exceptions\PaginationLimitException;
use Passorde\Api\Exceptions\WritesDisabledException;
use Passorde\Licence\Exceptions\LicenceException;

/**
 * Relative paths are scoped to the configured company; paths starting with /api/ go out as they are.
 */
interface EvolizApi
{
    /**
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed> The decoded JSON body.
     *
     * @throws ApiException On any response other than 2xx with a JSON body.
     * @throws ApiConfigurationException
     * @throws ConnectionException
     * @throws ForbiddenPathException
     * @throws InvalidPathException
     * @throws LicenceException
     */
    public function get(string $path, array $query = []): array;

    /**
     * Lazily yields one array of rows per page, stopping at meta.last_page.
     *
     * @param  array<string, mixed>  $query
     * @return Generator<int, array<int, mixed>>
     *
     * @throws PaginationLimitException When more pages remain after the hard cap.
     * @throws ApiException
     * @throws ApiConfigurationException
     * @throws ConnectionException
     * @throws ForbiddenPathException
     * @throws InvalidPathException
     * @throws LicenceException
     */
    public function paginate(string $path, array $query = [], int $perPage = 100): Generator;

    /**
     * Returns the raw response, without throwing on 4xx or 5xx.
     *
     * @param  array{query?: array<string, mixed>, json?: array<array-key, mixed>, timeout?: int}  $options
     *
     * @throws ApiException When the login that the call needs fails.
     * @throws ApiConfigurationException
     * @throws ConnectionException
     * @throws ForbiddenPathException
     * @throws InvalidPathException
     * @throws LicenceException
     * @throws WritesDisabledException
     */
    public function send(string $method, string $path, array $options = []): Response;
}
