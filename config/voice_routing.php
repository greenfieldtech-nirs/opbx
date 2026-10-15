<?php

declare(strict_types=1);

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
];
