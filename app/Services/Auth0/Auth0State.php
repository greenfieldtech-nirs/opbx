<?php

declare(strict_types=1);

namespace App\Services\Auth0;

final readonly class Auth0State
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $state,
        public string $codeVerifier,
        public array $payload,
    ) {}
}
