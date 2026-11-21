<?php

namespace Passorde\Writes;

use Passorde\Drafts\ArticleDraft;
use Passorde\Evoliz\EvolizConnector;
use Passorde\Licence\LicenceGuard;
use Passorde\Records\ArticleRecord;
use Passorde\Records\Text;
use Passorde\Writes\Results\AlreadyExists;
use Passorde\Writes\Results\Created;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Support\Mirror;
use Passorde\Writes\Support\WriteCalls;
use Passorde\Writes\Support\WriteLock;

final class CreateArticle
{
    public function __construct(
        private readonly WriteCalls $calls,
        private readonly Mirror $mirror,
        private readonly WriteLock $lock,
        private readonly LicenceGuard $licence,
    ) {}

    /**
     * Looks the reference up first: Evoliz's API cannot delete an article, so a duplicate would stay.
     *
     * @return Created<ArticleRecord>|AlreadyExists<ArticleRecord>|Refused|Failed
     */
    public function run(ArticleDraft $draft): Created|AlreadyExists|Refused|Failed
    {
        // Second line of defence: the client checks again, but a host may bind its own EvolizApi.
        $this->licence->check(EvolizConnector::ID);

        return $this->lock->around('article:'.hash('sha256', mb_strtoupper(trim($draft->reference))), function () use ($draft): Created|AlreadyExists|Refused|Failed {
            $existing = $this->byReference($draft->reference);

            if ($existing !== null) {
                return $this->exists($existing, false);
            }

            $sent = $this->calls->send('article.create', 'POST', '/articles', $draft->toRequest(), fn (): ?array => $this->byReference($draft->reference, singleAttempt: true));

            if ($sent->problem !== null) {
                return $sent->problem;
            }

            if ($sent->recovered) {
                return $this->exists($sent->row, true);
            }

            $record = ArticleRecord::fromRow($sent->row);

            if ($record === null) {
                return self::unreadable();
            }

            $this->mirror->article($record);

            return new Created($record);
        });
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return AlreadyExists<ArticleRecord>|Failed
     */
    private function exists(array $row, bool $afterUncertainResponse): AlreadyExists|Failed
    {
        $record = ArticleRecord::fromRow($row);

        if ($record === null) {
            return self::unreadable();
        }

        $this->mirror->article($record);

        return new AlreadyExists($record, $afterUncertainResponse);
    }

    /**
     * Evoliz stores references HTML-encoded, and treats their case as not significant in its screens.
     *
     * @return array<array-key, mixed>|null
     */
    private function byReference(string $reference, bool $singleAttempt = false): ?array
    {
        $wanted = mb_strtoupper(trim($reference));

        return $this->calls->first(
            'article.lookup',
            '/articles',
            ['reference' => trim($reference)],
            fn (array $row): bool => mb_strtoupper((string) Text::clean($row['reference'] ?? null)) === $wanted,
            $singleAttempt,
        );
    }

    private static function unreadable(): Failed
    {
        return new Failed(null, 'Evoliz a répondu sans identifiant d\'article.', uncertain: true);
    }
}
