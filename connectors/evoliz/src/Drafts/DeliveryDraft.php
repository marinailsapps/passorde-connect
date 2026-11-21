<?php

namespace Passorde\Drafts;

use DateTimeInterface;

final readonly class DeliveryDraft
{
    public string $documentDate;

    /**
     * @param  string  $externalNumber  The caller's own reference, unique for this client: what makes a repeated call safe.
     * @param  DateTimeInterface|string  $documentDate  May be ahead of today; Evoliz refuses more than 30 days ahead. Formatted as given, so mind its timezone.
     * @param  int  $paytermId  Evoliz requires a payment term.
     * @param  bool  $recoveryIndemnity  Evoliz does not apply the company's setting to a delivery created through its API.
     * @param  list<DeliveryLineDraft>  $lines
     */
    public function __construct(
        public int $clientId,
        public string $externalNumber,
        DateTimeInterface|string $documentDate,
        public int $paytermId,
        public array $lines,
        public ?int $paytypeId = null,
        public bool $recoveryIndemnity = true,
        public ?string $comment = null,
    ) {
        Input::id('clientId', $clientId);
        Input::required('externalNumber', $externalNumber);
        Input::id('paytermId', $paytermId);

        if ($paytypeId !== null) {
            Input::id('paytypeId', $paytypeId);
        }

        if ($lines === []) {
            throw InvalidDraftException::field('lines', 'au moins une ligne');
        }

        $this->documentDate = self::date($documentDate);
    }

    /**
     * POST /deliveries.
     *
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $term = ['paytermid' => $this->paytermId];

        if ($this->paytypeId !== null) {
            $term['paytypeid'] = $this->paytypeId;
        }

        $term['recovery_indemnity'] = $this->recoveryIndemnity;

        $body = [
            'documentdate' => $this->documentDate,
            'clientid' => $this->clientId,
            'external_document_number' => trim($this->externalNumber),
            'business_process' => 'goods',
            'term' => $term,
        ];

        if ($this->comment !== null && trim($this->comment) !== '') {
            $body['comment'] = trim($this->comment);
        }

        $body['items'] = [];

        foreach ($this->lines as $line) {
            $body['items'][] = $line->toRequest();
        }

        return $body;
    }

    private static function date(DateTimeInterface|string $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));

        if ($parsed === false || $parsed->format('Y-m-d') !== trim($date)) {
            throw InvalidDraftException::field('documentDate', 'date AAAA-MM-JJ attendue');
        }

        return trim($date);
    }
}
