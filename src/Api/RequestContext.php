<?php

namespace Passorde\Api;

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Links the logged requests to a record of the host application, such as the order that a delivery is created for.
 *
 *     RequestContext::subject('order', $order->id, fn () => app(CreateDelivery::class)->run($draft));
 *
 * Every request logged while the closure runs gets that subject in passorde_requests.subject_type and subject_id.
 * Subjects nest: the innermost one applies, and the outer one comes back when it ends, even on an exception.
 */
final class RequestContext
{
    /** @var list<array{type: string, id: int}> */
    private static array $subjects = [];

    /**
     * @template T
     *
     * @param  string  $type  The host's name for the kind of record, a morph alias or a class name.
     * @param  int|string  $id  A positive integer, or a string holding one: the column is an unsigned big integer.
     * @param  Closure(): T  $work
     * @return T What $work returns.
     *
     * @throws InvalidArgumentException When the type is empty or the id is not a positive integer.
     * @throws Throwable Whatever $work throws, untouched: it runs the host's own code.
     */
    public static function subject(string $type, int|string $id, Closure $work): mixed
    {
        $type = trim($type);

        if ($type === '' || strlen($type) > 255) {
            throw new InvalidArgumentException('Passorde: subject type must be a non-empty string of at most 255 characters.');
        }

        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw new InvalidArgumentException('Passorde: subject id must be a positive integer or a string holding one.');
        }

        self::$subjects[] = ['type' => $type, 'id' => $id];
        $depth = count(self::$subjects);

        try {
            return $work();
        } finally {
            self::$subjects = array_slice(self::$subjects, 0, $depth - 1);
        }
    }

    /**
     * The subject that applies now, or null outside any subject().
     *
     * @return array{type: string, id: int}|null
     */
    public static function current(): ?array
    {
        return self::$subjects === [] ? null : self::$subjects[array_key_last(self::$subjects)];
    }
}
