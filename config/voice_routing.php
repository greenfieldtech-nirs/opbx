<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Voice Routing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration values for voice routing services. These were previously
    | hardcoded magic numbers throughout the codebase.
    |
    */

    // Cache TTL settings (in seconds)
    'cache' => [
        'extension_ttl' => env('VOICE_CACHE_EXTENSION_TTL', 1800), // 30 minutes
        'business_hours_ttl' => env('VOICE_CACHE_BUSINESS_HOURS_TTL', 900), // 15 minutes
    ],

    // Lock settings for distributed operations
    'locks' => [
        'timeout_seconds' => env('VOICE_LOCK_TIMEOUT', 30),
        'block_seconds' => env('VOICE_LOCK_BLOCK', 3),
    ],

    // Retry settings
    'retry' => [
        'max_attempts' => env('VOICE_MAX_RETRIES', 3),
    ],

    // Idempotency settings
    'idempotency' => [
        'ttl_seconds' => env('VOICE_IDEMPOTENCY_TTL', 3600), // 1 hour
    ],

    // Call routing settings
    'call' => [
        'no_answer_timeout_seconds' => env('VOICE_NO_ANSWER_TIMEOUT', 30),
    ],
];
