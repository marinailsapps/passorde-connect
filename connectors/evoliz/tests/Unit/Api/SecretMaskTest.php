<?php

use Passorde\Api\SecretMask;

it('hides secrets by name at any depth, and leaves the rest and nulls alone', function () {
    expect(SecretMask::values([
        'name' => 'Exemple Test',
        'secret_key' => 'x',
        'access_token' => 'x',
        'password' => 'x',
        'Authorization' => 'Bearer x',
        'bank_information' => ['bank_name' => 'Banque Fictive', 'iban' => 'x', 'bank_account_detail' => 'x', 'bank_identification_code' => 'x'],
        'term' => ['bank' => ['bankid' => 5, 'iban' => null, 'bic' => 'x']],
        'items' => [['api_key' => 'x', 'quantity' => 2]],
        'webdoc' => 'https://evoliz.test/x',
    ]))->toBe([
        'name' => 'Exemple Test',
        'secret_key' => '***',
        'access_token' => '***',
        'password' => '***',
        'Authorization' => '***',
        'bank_information' => ['bank_name' => 'Banque Fictive', 'iban' => '***', 'bank_account_detail' => '***', 'bank_identification_code' => '***'],
        'term' => ['bank' => ['bankid' => 5, 'iban' => null, 'bic' => '***']],
        'items' => [['api_key' => '***', 'quantity' => 2]],
        'webdoc' => '***',
    ]);
});

it('hides the configured keys wherever they appear in a text', function () {
    config()->set('passorde-connect.evoliz.secret_key', 'fictional-secret-value');
    config()->set('passorde-connect.evoliz.public_key', 'fictional-public-key');

    expect(SecretMask::text('login refused for fictional-public-key / fictional-secret-value'))->toBe('login refused for *** / ***')
        ->and(SecretMask::values(['echo' => 'sent fictional-secret-value']))->toBe(['echo' => 'sent ***']);
});
