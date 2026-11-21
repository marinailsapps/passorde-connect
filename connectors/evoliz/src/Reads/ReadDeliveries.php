<?php

namespace Passorde\Reads;

use DateTimeInterface;
use Generator;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Records\DeliveryRecord;

final class ReadDeliveries
{
    private readonly EvolizApi $api;

    public function __construct(private readonly Listing $listing, ?EvolizApi $api = null)
    {
        $this->api = $api ?? app(EvolizApi::class);
    }

    /**
     * Deliveries whose document date falls between the two dates, both included, newest first.
     * Evoliz's search filter answers 400, so it is never used.
     *
     * @return Generator<int, DeliveryRecord>
     */
    public function window(DateTimeInterface $from, DateTimeInterface $to): Generator
    {
        return $this->listing->records('/deliveries', [
            'period' => 'custom',
            'date_min' => $from->format('Y-m-d'),
            'date_max' => $to->format('Y-m-d'),
        ], DeliveryRecord::fromRow(...));
    }

    /**
     * One delivery, lines included, mapped as the window maps it.
     *
     * @return DeliveryRecord|null Null when Evoliz does not know this delivery.
     *
     * @throws ApiException On any other error.
     */
    public function one(int $id): ?DeliveryRecord
    {
        try {
            $body = $this->api->get('/deliveries/'.$id);
        } catch (ApiException $exception) {
            if ($exception->error->status === 404) {
                return null;
            }

            throw $exception;
        }

        return DeliveryRecord::fromRow(is_array($body['data'] ?? null) ? $body['data'] : $body);
    }
}
