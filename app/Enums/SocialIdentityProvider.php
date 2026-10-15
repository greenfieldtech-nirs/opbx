<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialIdentityProvider: string
{
    case GOOGLE = 'google';
    case GITHUB = 'github';

    // No longer supported for sign-in or linking; kept only so identities and join
    // requests stored before support was dropped still load (the column is cast to this enum).
    case FACEBOOK = 'facebook';
    case MICROSOFT = 'microsoft';
    case X = 'x';

    /** Providers users can sign in with and link: Google and GitHub only. */
    public const SUPPORTED = [self::GOOGLE, self::GITHUB];

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

    public function isSupported(): bool
    {
        return in_array($this, self::SUPPORTED, true);
    }

    /**
     * Values of the supported providers (the request allow-list).
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $provider) => $provider->value, self::SUPPORTED);
    }
}
