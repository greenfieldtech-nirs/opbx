<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Cloudonix API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Cloudonix CPaaS API base URL and timeout.
    | API credentials are stored per-organization in the database.
    |
    */

    'api' => [
        'base_url' => env('CLOUDONIX_API_BASE_URL', 'https://api.cloudonix.io'),
        'timeout' => env('CLOUDONIX_API_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | SIP Server Configuration
    |--------------------------------------------------------------------------
    |
    | Default SIP server for extension registrations.
    |
    */

    'sip_server' => env('CLOUDONIX_SIP_SERVER', 'sip.cloudonix.io'),

    /*
    |--------------------------------------------------------------------------
    | CXML Configuration
    |--------------------------------------------------------------------------
    |
    | Default timeout for CXML verb responses.
    |
    */

    // When true, webhooks for orgs without a domain_requests_api_key get 401.
    'webhooks' => [
        'require_auth' => (bool) env('CLOUDONIX_WEBHOOK_REQUIRE_AUTH', false),
    ],

    'cxml' => [
        'default_timeout' => (int) env('CXML_DEFAULT_TIMEOUT', 30),
    ],
];
