<?php

declare(strict_types=1);

namespace App\Services\Auth0;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

class Auth0StateStore
{
    private const TTL_SECONDS = 600;

    public function create(string $provider, string $intent, ?int $userId = null): Auth0State
    {
        $state = Str::random(64);
        $codeVerifier = Str::random(128);

        $payload = [
            'provider' => $provider,
            'intent' => $intent,
            'user_id' => $userId,
            'nonce' => Str::random(32),
        ];

        Cache::put($this->key($state), Crypt::encryptString(json_encode([
            'code_verifier' => $codeVerifier,
            'payload' => $payload,
        ])), self::TTL_SECONDS);

        return new Auth0State($state, $codeVerifier, $payload);
    }

    public function consume(string $state): Auth0State
    {
        $encrypted = Cache::pull($this->key($state));

        if ($encrypted === null) {
            throw new RuntimeException('Invalid or expired Auth0 state.');
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true);
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid Auth0 state.', previous: $e);
        }

        return new Auth0State(
            $state,
            $decoded['code_verifier'],
            $decoded['payload']
        );
    }

    private function key(string $state): string
    {
        return 'auth0:state:'.$state;
    }
}
