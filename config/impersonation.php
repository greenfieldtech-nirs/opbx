<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Impersonation Token TTL
    |--------------------------------------------------------------------------
    |
    | Lifetime (in minutes) of a platform-manager impersonation token. Kept short
    | since impersonation grants full owner-role access inside a target
    | organization. Must be <= the global Sanctum expiration to be honored.
    |
    */
    'ttl_minutes' => (int) env('IMPERSONATION_TTL_MINUTES', 60),
];
