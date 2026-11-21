<?php

namespace Passorde\Api;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

final class RetryAfter
{
    /**
     * The delay asked for by the response, in seconds, or null when it asks for none that can be read.
     * The header holds either a number of seconds or an HTTP date.
     */
    public static function seconds(Response $response): ?int
    {
        $value = trim($response->header('Retry-After'));

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $date = $value === '' ? false : strtotime($value);

        return $date === false ? null : max(0, $date - CarbonImmutable::now()->getTimestamp());
    }
}
