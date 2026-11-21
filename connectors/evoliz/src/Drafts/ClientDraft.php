<?php

namespace Passorde\Drafts;

final readonly class ClientDraft
{
    private const TYPES = ['Particulier', 'Professionnel', 'Administration publique'];

    /**
     * Evoliz checks almost nothing here (a four-digit postcode or a phone made of words is stored): Passorde validates them before.
     *
     * @param  string|null  $addressLine  Null or empty leaves the street out of the request, as for a client with no known address.
     * @param  string|null  $postcode  Null or empty leaves it out. Two clients with the same name are told apart by it: see ClientCode.
     * @param  string|null  $town  Null or empty leaves it out.
     * @param  string|null  $code  The Evoliz-side code to prefer. Without one, it comes from the name; taken by another client, it gets a number.
     * @param  string  $vatNumber  Evoliz requires one, and "NC" is what it accepts from a client without any.
     */
    public function __construct(
        public string $name,
        public ?string $addressLine = null,
        public ?string $postcode = null,
        public ?string $town = null,
        public string $countryIso2 = 'FR',
        public ?string $phone = null,
        public ?string $mobile = null,
        public ?string $code = null,
        public string $vatNumber = 'NC',
        public string $type = 'Professionnel',
        public ?string $addressLine2 = null,
        public ?string $addressLine3 = null,
    ) {
        Input::required('name', $name);
        Input::required('vatNumber', $vatNumber);

        if (preg_match('/^[A-Za-z]{2}$/', $countryIso2) !== 1) {
            throw InvalidDraftException::field('countryIso2', 'code pays à deux lettres attendu');
        }

        if (! in_array($type, self::TYPES, true)) {
            throw InvalidDraftException::field('type', 'attendu : '.implode(', ', self::TYPES));
        }
    }

    /**
     * POST /clients. The address block always goes, with the country at least; a street, postcode or town that is
     * null or empty is left out of it, as earlier integrations sent it.
     *
     * @return array<string, mixed>
     */
    public function toRequest(string $code): array
    {
        return self::withoutEmpty([
            'type' => $this->type,
            'name' => trim($this->name),
            'code' => $code,
            'vat_number' => trim($this->vatNumber),
            'address' => self::withoutEmpty([
                'addr' => Input::patchValue($this->addressLine),
                'addr2' => $this->addressLine2,
                'addr3' => $this->addressLine3,
                'postcode' => Input::patchValue($this->postcode),
                'town' => Input::patchValue($this->town),
                'iso2' => strtoupper($this->countryIso2),
            ]),
            'phone' => $this->phone,
            'mobile' => $this->mobile,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function withoutEmpty(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
