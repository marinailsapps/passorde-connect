<?php

// Passorde Connect — Evoliz, compatible avec Evoliz, non affilié à Evoliz SAS / Visma.
// Merged by Passorde\Evoliz\EvolizServiceProvider under passorde-connect.evoliz, so a host reads
// config('passorde-connect.evoliz.company_id'). Set through the PASSORDE_EVOLIZ_* variables (.env.example beside this
// folder); a host may also put an 'evoliz' array in its config/passorde-connect.php, whose keys win over these.
// An empty value in .env reaches env() as an empty string, so defaults use ?: rather than env()'s second argument.

return [

    'base_url' => env('PASSORDE_EVOLIZ_BASE_URL') ?: null,
    'company_id' => env('PASSORDE_EVOLIZ_COMPANY_ID') ?: null,
    'public_key' => env('PASSORDE_EVOLIZ_PUBLIC_KEY') ?: null,
    'secret_key' => env('PASSORDE_EVOLIZ_SECRET_KEY') ?: null,

    // Seconds.
    'timeout' => (int) (env('PASSORDE_EVOLIZ_TIMEOUT') ?: 30),
    'connect_timeout' => (int) (env('PASSORDE_EVOLIZ_CONNECT_TIMEOUT') ?: 10),

    // Never called, for any method: the Evoliz connector does not invoice. Comma-separated in .env.
    // These add to Passorde\Api\ForbiddenPaths::ALWAYS, which cannot be switched off.
    // Whole segments, anywhere in the path, whatever the case; * or {name} stands for any one segment.
    'forbidden_paths' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) (env('PASSORDE_EVOLIZ_FORBIDDEN_PATHS') ?: '/invoices,/deliveries/{id}/invoice,/invoice,/progress-invoice,/credits,/recoveries')),
    ))),

    // A paginate() that needs more pages than this throws rather than returning a partial list.
    'max_pages' => 1000,

    'rate_limit' => [
        'low_remaining' => 10,
        'pause_seconds' => 60,
        'max_wait_seconds' => 60,
    ],

];
