<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialIdentityProvider: string
{
    case GOOGLE = 'google';
    case FACEBOOK = 'facebook';
    case MICROSOFT = 'microsoft';
    case GITHUB = 'github';
    case X = 'x';

    public function auth0Connection(): string
    {
        return match ($this) {
            self::GOOGLE => 'google-oauth2',
            self::FACEBOOK => 'facebook',
            self::MICROSOFT => 'windowslive',
            self::GITHUB => 'github',
            self::X => 'twitter',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $provider) => $provider->value, self::cases());
    }
}
