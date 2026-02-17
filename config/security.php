<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Content Security Policy (CSP) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure Content Security Policy directives for enhanced security.
    |
    */

    /**
     * CSP Violation Reporting Endpoint
     *
     * URL where browsers should send CSP violation reports.
     * Set to null to disable reporting.
     *
     * Example: '/api/v1/security/csp-report'
     */
    'csp_report_uri' => env('CSP_REPORT_URI', null),

    /**
     * Additional domains allowed for AJAX/fetch requests (connect-src)
     *
     * Array of fully-qualified domain URLs that are allowed for XHR/fetch.
     * The Cloudonix API domain is automatically included if configured.
     *
     * Example: ['https://api.example.com', 'https://cdn.example.com']
     */
    'csp_connect_domains' => array_filter(
        explode(',', env('CSP_CONNECT_DOMAINS', '')),
        fn ($domain) => ! empty(trim($domain))
    ),

    /*
    |--------------------------------------------------------------------------
    | Security Headers Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Strict-Transport-Security (HSTS) max-age
     *
     * Duration in seconds that browsers should remember to only access
     * the site via HTTPS. Default: 1 year (31536000 seconds)
     */
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),

    /**
     * Include subdomains in HSTS policy
     */
    'hsts_include_subdomains' => env('HSTS_INCLUDE_SUBDOMAINS', true),

    /**
     * HSTS preload (submit domain to browser preload lists)
     *
     * Only enable if you've submitted your domain to the HSTS preload list.
     * See: https://hstspreload.org/
     */
    'hsts_preload' => env('HSTS_PRELOAD', false),
];
