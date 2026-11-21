<?php

namespace Passorde\Mock;

/**
 * Records in the shapes Evoliz answers. Evoliz stores text HTML-encoded, so these do too.
 */
final class Rows
{
    private const COUNTRIES = ['FR' => 'France', 'BE' => 'Belgique', 'CH' => 'Suisse', 'LU' => 'Luxembourg'];

    /**
     * @param  array<string, mixed>  $address  addr, addr2, addr3, postcode, town, iso2.
     * @return array<string, mixed>
     */
    public static function client(int $id, string $code, string $name, string $type, array $address, string $vatNumber, ?string $phone = null, ?string $mobile = null, bool $enabled = true): array
    {
        return [
            'clientid' => $id,
            'userid' => 91,
            'code' => $code,
            'civility' => '',
            'name' => $name,
            'type' => $type,
            'vat_number' => $vatNumber,
            'bank_information' => ['bank_name' => '', 'bank_account_detail' => '', 'iban' => '', 'bank_identification_code' => ''],
            'address' => self::address($address),
            'delivery_address' => null,
            'phone' => $phone,
            'mobile' => $mobile,
            'ttc' => false,
            'comment' => '',
            'enabled' => $enabled,
            'custom_fields' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $address  addr, addr2, addr3, postcode, town, iso2.
     * @return array<string, mixed>
     */
    public static function address(array $address): array
    {
        $iso2 = strtoupper((string) ($address['iso2'] ?? 'FR'));

        return [
            'addr' => $address['addr'] ?? null,
            'addr2' => $address['addr2'] ?? null,
            'addr3' => $address['addr3'] ?? null,
            'postcode' => $address['postcode'] ?? null,
            'town' => $address['town'] ?? null,
            'country' => ['label' => self::COUNTRIES[$iso2] ?? $iso2, 'iso2' => $iso2],
        ];
    }

    /**
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>
     */
    public static function contact(int $id, array $client, string $lastName, ?string $firstName, ?string $email, bool $favourite): array
    {
        return [
            'contactid' => $id,
            'userid' => 91,
            'client' => ['clientid' => $client['clientid'], 'code' => $client['code'], 'civility' => null, 'name' => $client['name']],
            'civility' => null,
            'lastname' => $lastName,
            'firstname' => $firstName,
            'email' => $email,
            'profil' => null,
            'tel_primary' => null,
            'favorite' => $favourite,
            'enabled' => true,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $classification
     * @return array<string, mixed>
     */
    public static function article(int $id, string $reference, string $designation, float $unitPrice, float $vat, ?array $classification, bool $stockManaged, ?float $stock, string $nature = 'product', bool $enabled = true): array
    {
        return [
            'articleid' => $id,
            'userid' => 91,
            'reference' => $reference,
            'reference_clean' => $reference,
            'nature' => $nature,
            'sale_classification' => $classification === null ? null : ['id' => $classification['classificationid'], 'code' => $classification['code'], 'label' => $classification['label']],
            'designation' => $designation,
            'designation_clean' => strip_tags($designation),
            'quantity' => 1,
            'unit' => '',
            'unit_price_vat_exclude' => $unitPrice,
            'unit_price_vat_include' => round($unitPrice * (1 + $vat / 100), 2),
            'vat' => $vat,
            'ttc' => false,
            'supplier' => ['supplierid' => 71, 'code' => 'FOURNFICT', 'name' => 'Fournisseur Fictif'],
            'stock_management' => $stockManaged,
            'stocked_quantity' => $stock,
            'enabled' => $enabled,
            'custom_fields' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array<string, mixed>
     */
    public static function item(int $id, array $article, float $quantity, ?float $unitPrice = null, ?string $designation = null): array
    {
        $price = $unitPrice ?? (float) $article['unit_price_vat_exclude'];
        $text = $designation ?? (string) $article['designation'];

        return [
            'itemid' => $id,
            'articleid' => $article['articleid'],
            'reference' => $article['reference'],
            'reference_clean' => $article['reference_clean'],
            'designation' => $text,
            'designation_clean' => strip_tags($text),
            'quantity' => $quantity,
            'type' => 'article',
            'unit_price_vat_exclude' => $price,
            'vat' => $article['vat'],
            'total' => ['vat_exclude' => round($quantity * $price, 2)],
        ];
    }

    /**
     * @param  array<string, mixed>  $client
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $payterm
     * @param  array<string, mixed>|null  $paytype
     * @return array<string, mixed>
     */
    public static function delivery(int $id, string $number, array $client, string $date, array $items, array $payterm, ?array $paytype, bool $recoveryIndemnity, ?string $external, ?string $comment, ?string $invoicedOn = null): array
    {
        $excluded = 0.0;
        $vat = 0.0;
        $quantity = 0.0;

        foreach ($items as $item) {
            $excluded += (float) $item['total']['vat_exclude'];
            $vat += (float) $item['total']['vat_exclude'] * (float) $item['vat'] / 100;
            $quantity += (float) $item['quantity'];
        }

        return [
            'deliveryid' => $id,
            'document_number' => $number,
            'userid' => 91,
            'client' => ['clientid' => $client['clientid'], 'code' => $client['code'], 'name' => $client['name']],
            'total' => ['vat_exclude' => round($excluded, 2), 'vat' => round($vat, 2), 'vat_include' => round($excluded + $vat, 2), 'total_quantity' => $quantity],
            'status_code' => $invoicedOn === null ? 2 : 20,
            'status' => $invoicedOn === null ? 'create' : 'invoice',
            'status_dates' => ['create' => $date, 'invoice' => $invoicedOn],
            'documentdate' => $date,
            'term' => [
                'recovery_indemnity' => $recoveryIndemnity,
                'paytype' => $paytype,
                'payterm' => $payterm,
                'bank' => ['bankid' => 5, 'label' => 'Banque fictive', 'iban' => 'FR00 0000 0000 0000', 'bic' => 'FICTFRPP'],
            ],
            'comment' => $comment,
            'comment_clean' => $comment === null ? null : strip_tags($comment),
            'external_document_number' => $external,
            'enabled' => true,
            'webdoc' => 'https://mock.invalid/webdoc/'.$id,
            'items' => $items,
        ];
    }
}
