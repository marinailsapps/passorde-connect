<?php

namespace Passorde\Reads;

use Generator;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Records\ArticleRecord;

final class ReadArticles
{
    public function __construct(private readonly EvolizApi $api, private readonly Listing $listing) {}

    /**
     * @return Generator<int, ArticleRecord>
     */
    public function all(): Generator
    {
        return $this->listing->records('/articles', [], ArticleRecord::fromRow(...));
    }

    /**
     * @return ArticleRecord|null Null when Evoliz does not know this article.
     */
    public function one(int $id): ?ArticleRecord
    {
        try {
            $body = $this->api->get('/articles/'.$id);
        } catch (ApiException $exception) {
            if ($exception->error->status === 404) {
                return null;
            }

            throw $exception;
        }

        return ArticleRecord::fromRow(is_array($body['data'] ?? null) ? $body['data'] : $body);
    }
}
