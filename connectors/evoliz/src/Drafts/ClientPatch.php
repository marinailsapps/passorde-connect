<?php

namespace Passorde\Drafts;

/**
 * Only what changes: null leaves a field alone, an empty text clears it.
 */
final readonly class ClientPatch
{
    /**
     * @param  string|null  $addressLine  Replaces the whole street address: a second or third line not given here is cleared.
     *                                    An empty text clears the street, sending addr: null.
     * @param  string|null  $postcode  Cannot be cleared: Evoliz documents it as not nullable.
     * @param  string|null  $town  Cannot be cleared: Evoliz documents it as not nullable.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $addressLine = null,
        public ?string $addressLine2 = null,
        public ?string $addressLine3 = null,
        public ?string $postcode = null,
        public ?string $town = null,
        public ?string $countryIso2 = null,
        public ?string $phone = null,
        public ?string $mobile = null,
        public ?string $vatNumber = null,
    ) {
        if ($name !== null) {
            Input::required('name', $name);
        }

        if ($vatNumber !== null) {
            Input::required('vatNumber', $vatNumber);
        }

        // The API documentation marks address.addr nullable on PATCH, and neither postcode nor town.
        if ($postcode !== null) {
            Input::required('postcode', $postcode);
        }

        if ($town !== null) {
            Input::required('town', $town);
        }

        if ($countryIso2 !== null && preg_match('/^[A-Za-z]{2}$/', $countryIso2) !== 1) {
            throw InvalidDraftException::field('countryIso2', 'code pays à deux lettres attendu');
        }

        if ($this->toRequest() === []) {
            throw InvalidDraftException::field('patch', 'aucun champ à modifier');
        }
    }

    /**
     * PATCH /clients/{id}. Evoliz keeps what a partial object leaves out, also inside the address.
     *
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $address = [];

        if ($this->addressLine !== null) {
            $address = [
                'addr' => Input::patchValue($this->addressLine),
                'addr2' => Input::patchValue($this->addressLine2),
                'addr3' => Input::patchValue($this->addressLine3),
            ];
        } else {
            $address = self::given(['addr2' => $this->addressLine2, 'addr3' => $this->addressLine3]);
        }

        $address += self::given(['postcode' => $this->postcode, 'town' => $this->town]);

        if ($this->countryIso2 !== null) {
            $address['iso2'] = strtoupper($this->countryIso2);
        }

        $body = self::given(['name' => $this->name]);

        if ($address !== []) {
            $body['address'] = $address;
        }

        return $body + self::given(['phone' => $this->phone, 'mobile' => $this->mobile, 'vat_number' => $this->vatNumber]);
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string|null>
     */
    private static function given(array $values): array
    {
        return array_map(
            Input::patchValue(...),
            array_filter($values, fn (?string $value): bool => $value !== null),
        );
    }
}
