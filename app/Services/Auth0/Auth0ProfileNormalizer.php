<?php

declare(strict_types=1);

namespace App\Services\Auth0;

use App\Enums\SocialIdentityProvider;

class Auth0ProfileNormalizer
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function normalize(SocialIdentityProvider $provider, array $profile): array
    {
        return [
            'subject' => $profile['sub'] ?? '',
            'email' => strtolower((string) ($profile['email'] ?? '')),
            'email_verified' => (bool) ($profile['email_verified'] ?? false),
            'name' => $profile['name'] ?? ($profile['nickname'] ?? ''),
            'picture' => $profile['picture'] ?? null,
            'provider' => $provider,
            'raw' => $profile,
        ];
    }
}
