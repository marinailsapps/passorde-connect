<?php

namespace Passorde\Records;

final readonly class ClientRecord implements SyncedRecord
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public int $id,
        public ?string $code,
        public ?string $name,
        public ?string $addressLine,
        public ?string $postcode,
        public ?string $town,
        public ?string $countryIso2,
        public ?string $phone,
        public ?string $mobile,
        public ?string $vatNumber,
        public bool $enabled,
        public ?ContactRecord $contact,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function fromRow(array $row, ?ContactRecord $contact = null): ?self
    {
        $id = Row::id($row, 'clientid');

        if ($id === null) {
            return null;
        }

        $address = Row::nested($row, 'address');
        // Evoliz answers address.country.iso2; address.iso2 is what it accepts in a request.
        $iso2 = Text::clean(Row::nested($address, 'country')['iso2'] ?? $address['iso2'] ?? null);

        return new self(
            id: $id,
            code: Text::clean($row['code'] ?? null),
            name: Text::clean($row['name'] ?? null),
            addressLine: Text::join([$address['addr'] ?? null, $address['addr2'] ?? null, $address['addr3'] ?? null], ', '),
            postcode: Text::clean($address['postcode'] ?? null),
            town: Text::clean($address['town'] ?? null),
            countryIso2: $iso2 === null ? null : strtoupper($iso2),
            phone: Text::clean($row['phone'] ?? null),
            mobile: Text::clean($row['mobile'] ?? null),
            vatNumber: Text::clean($row['vat_number'] ?? null),
            enabled: Row::bool($row, 'enabled', true),
            contact: $contact,
            raw: $row,
        );
    }

    public function evolizId(): int
    {
        return $this->id;
    }
}
