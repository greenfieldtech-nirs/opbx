<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook Idempotency Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for webhook idempotency handling to prevent duplicate
    | processing of webhook events.
    |
    */

    'idempotency' => [
        /*
        |--------------------------------------------------------------------------
        | Idempotency Key TTL
        |--------------------------------------------------------------------------
        |
        | How long to cache idempotency keys (in seconds). After this time,
        | duplicate webhook events will be processed again.
        | Default: 86400 seconds (24 hours)
        |
        */
        'ttl' => env('WEBHOOK_IDEMPOTENCY_TTL', 86400),

        /*
        |--------------------------------------------------------------------------
        | Maximum Response Cache Size
        |--------------------------------------------------------------------------
        |
        | Maximum size of webhook responses to cache for idempotency (in bytes).
        | Responses larger than this will only cache metadata, not full content.
        | This prevents Redis memory exhaustion from large responses.
        | Default: 102400 bytes (100KB)
        |
        */
        'max_response_size' => env('WEBHOOK_MAX_CACHE_SIZE', 102400),
    ],
];
