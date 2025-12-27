<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Cloudonix API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Cloudonix CPaaS API integration.
    |
    */

    'api_base_url' => env('CLOUDONIX_API_BASE_URL', 'https://api.cloudonix.io'),

    'api_token' => env('CLOUDONIX_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Cloudonix webhook signature verification.
    | The webhook_secret is used to verify HMAC-SHA256 signatures.
    |
    */

    'webhook_secret' => env('CLOUDONIX_WEBHOOK_SECRET'),

    'verify_signature' => env('CLOUDONIX_VERIFY_SIGNATURE', true),

    /*
    |--------------------------------------------------------------------------
    | Signature Header Name
    |--------------------------------------------------------------------------
    |
    | The HTTP header name that contains the webhook signature.
    |
    */

    'signature_header' => env('CLOUDONIX_SIGNATURE_HEADER', 'X-Cloudonix-Signature'),
];
