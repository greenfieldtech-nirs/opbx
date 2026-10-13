<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Values are requests per minute unless otherwise specified.
    |
    | Cloudonix webhook routes (/api/webhooks/cloudonix/*) and voice routing /
    | callback routes (/api/voice/*, /api/callbacks/voice/*) are intentionally
    | NOT rate-limited: with many organisations Cloudonix fires hundreds of
    | events per second, and throttling them would drop calls and CDRs.
    | They are protected by signature / auth middleware and idempotency only.
    |
    */

    // Named limiter `api` - authenticated user requests (per user, or IP)
    'api' => env('RATE_LIMIT_API', 60),

    // Sensitive operations - password changes, role updates, etc.
    'sensitive' => env('RATE_LIMIT_SENSITIVE', 10),

    // Sensitive operations for admins - elevated limits
    'sensitive_admin' => env('RATE_LIMIT_SENSITIVE_ADMIN', 60),

    // Authentication routes - login/logout attempts per IP
    'auth' => env('RATE_LIMIT_AUTH', 5),

    // Decay period in seconds for sensitive operations
    'decay_seconds' => env('RATE_LIMIT_SENSITIVE_DECAY', 60),

    // Per-organization limits used by the `rate_limit_org:<type>` middleware.
    // Only `rate_limit_org:api` is applied to routes; `default` is the fallback.
    'organization' => [
        'api' => [
            'max_attempts' => (int) env('RATE_LIMIT_API', 60),
            'per_minutes' => (int) env('RATE_LIMIT_API_MINUTES', 1),
        ],

        'default' => [
            'max_attempts' => (int) env('RATE_LIMIT_DEFAULT', 60),
            'per_minutes' => (int) env('RATE_LIMIT_DEFAULT_MINUTES', 1),
        ],
    ],
];
