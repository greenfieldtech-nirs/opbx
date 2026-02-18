<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | Configure circuit breaker behavior for external service calls.
    | Circuit breakers prevent cascading failures by failing fast when
    | a service is experiencing issues.
    |
    */

    'cloudonix' => [
        // Number of consecutive failures before opening circuit
        'failure_threshold' => env('CIRCUIT_BREAKER_CLOUDONIX_THRESHOLD', 5),

        // Request timeout in seconds
        'timeout' => env('CIRCUIT_BREAKER_CLOUDONIX_TIMEOUT', 30),

        // Seconds to wait before attempting to close circuit
        'retry_after' => env('CIRCUIT_BREAKER_CLOUDONIX_RETRY_AFTER', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing Override
    |--------------------------------------------------------------------------
    |
    | In testing environment, disable circuit breaker to ensure test isolation.
    |
    */

    'enabled' => env('CIRCUIT_BREAKER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Circuit Breaker Settings
    |--------------------------------------------------------------------------
    |
    | Default settings for circuit breakers not explicitly configured above.
    |
    */

    'defaults' => [
        'failure_threshold' => env('CIRCUIT_BREAKER_DEFAULT_THRESHOLD', 5),
        'timeout' => env('CIRCUIT_BREAKER_DEFAULT_TIMEOUT', 30),
        'retry_after' => env('CIRCUIT_BREAKER_DEFAULT_RETRY_AFTER', 60),
    ],
];
