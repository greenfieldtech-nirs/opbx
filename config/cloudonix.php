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

    'api' => [
        'base_url' => env('CLOUDONIX_API_BASE_URL', 'https://api.cloudonix.io'),
        'token' => env('CLOUDONIX_API_TOKEN'),
        'timeout' => env('CLOUDONIX_API_TIMEOUT', 30),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Timestamp Validation
    |--------------------------------------------------------------------------
    |
    | Webhook timestamp validation prevents replay attacks by ensuring
    | webhooks are recent (within 5 minutes). Set require_timestamp to true
    | to enforce this validation.
    |
    */

    'timestamp_header' => env('CLOUDONIX_TIMESTAMP_HEADER', 'X-Cloudonix-Timestamp'),
    'require_timestamp' => env('CLOUDONIX_REQUIRE_TIMESTAMP', false),

    /*
    |--------------------------------------------------------------------------
    | IP Address Allowlist
    |--------------------------------------------------------------------------
    |
    | Optional IP address allowlist for webhook requests. If empty, all IPs
    | are allowed. If specified, only requests from these IPs will be accepted.
    | Format: comma-separated list in .env, e.g., "1.2.3.4,5.6.7.8"
    |
    */

    'webhook_allowed_ips' => array_filter(
        explode(',', env('CLOUDONIX_WEBHOOK_ALLOWED_IPS', '')),
        fn ($ip) => ! empty(trim($ip))
    ),
];
