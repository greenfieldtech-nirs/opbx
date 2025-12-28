<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure rate limits for different application endpoints.
    | Values are requests per minute unless otherwise specified.
    |
    */

    // API routes - authenticated user requests
    'api' => env('RATE_LIMIT_API', 60),

    // Webhook routes - external webhook requests per IP
    'webhooks' => env('RATE_LIMIT_WEBHOOKS', 100),

    // Voice routing routes - high traffic voice routing requests per IP
    'voice' => env('RATE_LIMIT_VOICE', 1000),

    // Sensitive operations - password changes, role updates, etc.
    'sensitive' => env('RATE_LIMIT_SENSITIVE', 10),

    // Authentication routes - login/logout attempts per IP
    'auth' => env('RATE_LIMIT_AUTH', 5),
];
