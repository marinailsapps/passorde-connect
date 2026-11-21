<?php

namespace Passorde\Drafts;

final readonly class DeliveryLineDraft
{
    /**
     * A line always names its article: on a free-form line Evoliz drops the VAT without a word.
     *
     * @param  int|float|string|null  $unitPriceHt  Null takes the article's price; 0 makes the line free. VAT and classification always come from the article.
     * @param  string|null  $designationOverride  HTML. It replaces the article's whole text, numbers included, so it must carry them: see withComment().
     */
    public function __construct(
        public int $articleId,
        public int|float|string $quantity,
        public int|float|string|null $unitPriceHt = null,
        public ?string $designationOverride = null,
    ) {
        Input::id('articleId', $articleId);

        if (Input::number('quantity', $quantity) === 0) {
            throw InvalidDraftException::field('quantity', 'quantité nulle');
        }

        if ($unitPriceHt !== null) {
            Input::number('unitPriceHt', $unitPriceHt);
        }
    }

    /**
     * The article's text as Evoliz stores it (ArticleRecord::$designationRaw) with a comment underneath: Evoliz has
     * no comment per line. The new line keeps the comment apart in designation_clean, which only drops the tag.
     */
    public static function withComment(string $designationRaw, string $comment): string
    {
        return $designationRaw."<br />\n".nl2br(htmlspecialchars(trim($comment), ENT_NOQUOTES));
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $item = [
            'type' => 'article',
            'articleid' => $this->articleId,
            'quantity' => Input::number('quantity', $this->quantity),
        ];

        if ($this->unitPriceHt !== null) {
            $item['unit_price'] = Input::number('unitPriceHt', $this->unitPriceHt);
        }

        if ($this->designationOverride !== null && trim($this->designationOverride) !== '') {
            $item['designation'] = $this->designationOverride;
        }

        return $item;
    }
}
