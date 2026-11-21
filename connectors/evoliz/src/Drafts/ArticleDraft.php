<?php

namespace Passorde\Drafts;

final readonly class ArticleDraft
{
    private const NATURES = ['product', 'service'];

    /**
     * @param  string  $designation  Plain text: Evoliz stores HTML, so it is escaped here.
     * @param  int|float|string|null  $stockQty  The starting stock, for a stock-managed article only.
     */
    public function __construct(
        public string $reference,
        public string $designation,
        public int|float|string $unitPriceHt,
        public int|float|string $vatRate,
        public ?int $classificationId = null,
        public bool $stockManaged = false,
        public int|float|string|null $stockQty = null,
        public string $nature = 'product',
    ) {
        Input::required('reference', $reference);
        Input::required('designation', $designation);
        Input::number('unitPriceHt', $unitPriceHt);
        Input::number('vatRate', $vatRate);

        if ($classificationId !== null) {
            Input::id('classificationId', $classificationId);
        }

        if ($stockQty !== null) {
            Input::number('stockQty', $stockQty);

            if (! $stockManaged) {
                throw InvalidDraftException::field('stockQty', 'un stock suppose stockManaged');
            }
        }

        if (! in_array($nature, self::NATURES, true)) {
            throw InvalidDraftException::field('nature', 'attendu : '.implode(', ', self::NATURES));
        }
    }

    /**
     * POST /articles.
     *
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $body = [
            'reference' => trim($this->reference),
            'nature' => $this->nature,
            'designation' => htmlspecialchars(trim($this->designation), ENT_NOQUOTES),
        ];

        if ($this->classificationId !== null) {
            $body['sale_classificationid'] = $this->classificationId;
        }

        $body += [
            'unit_price' => Input::number('unitPriceHt', $this->unitPriceHt),
            'vat_rate' => Input::number('vatRate', $this->vatRate),
            'ttc' => false,
            'stock_management' => $this->stockManaged,
        ];

        if ($this->stockManaged) {
            $body['stocked_quantity'] = Input::number('stockQty', $this->stockQty ?? 0);
        }

        return $body;
    }
}
