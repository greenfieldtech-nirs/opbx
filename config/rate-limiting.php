<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Per-Organization Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure rate limits per organization for different endpoint types.
    | This prevents a single organization from exhausting system resources.
    |
    */

    'voice_routing' => [
        'max_attempts' => (int) env('RATE_LIMIT_VOICE', 100),
        'per_minutes' => (int) env('RATE_LIMIT_VOICE_MINUTES', 1),
    ],

    'webhook' => [
        'max_attempts' => (int) env('RATE_LIMIT_WEBHOOKS', 200),
        'per_minutes' => (int) env('RATE_LIMIT_WEBHOOKS_MINUTES', 1),
    ],

    'api' => [
        'max_attempts' => (int) env('RATE_LIMIT_API', 60),
        'per_minutes' => (int) env('RATE_LIMIT_API_MINUTES', 1),
    ],

    'default' => [
        'max_attempts' => (int) env('RATE_LIMIT_DEFAULT', 60),
        'per_minutes' => (int) env('RATE_LIMIT_DEFAULT_MINUTES', 1),
    ],
];
