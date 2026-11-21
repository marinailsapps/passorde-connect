<?php

namespace Passorde\Reads;

use Generator;
use Illuminate\Support\Facades\Log;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\LoginFailedException;
use Passorde\Records\UserRecord;

final class ReadUsers
{
    public function __construct(private readonly Listing $listing) {}

    /**
     * Best effort: an API key without this scope yields an empty list and a warning.
     *
     * @return Generator<int, UserRecord>
     */
    public function all(): Generator
    {
        $received = false;

        try {
            foreach ($this->listing->records('/users', [], UserRecord::fromRow(...)) as $user) {
                $received = true;

                yield $user;
            }
        } catch (LoginFailedException $exception) {
            // Wrong credentials are not a missing scope.
            throw $exception;
        } catch (ApiException $exception) {
            // A refusal after the first rows is a failure: the partial list must not pass for the complete one.
            if ($received || ! in_array($exception->error->status, [401, 403], true)) {
                throw $exception;
            }

            Log::warning('Passorde: lecture des utilisateurs Evoliz refusée.', ['error' => $exception->getMessage()]);
        }
    }
}
