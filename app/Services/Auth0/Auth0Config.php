<?php

declare(strict_types=1);

namespace App\Services\Auth0;

use App\Enums\SocialIdentityProvider;
use InvalidArgumentException;

final readonly class Auth0Config
{
    /**
     * @param  array<int, SocialIdentityProvider>  $providers
     */
    public function __construct(
        public string $domain,
        public string $clientId,
        public string $clientSecret,
        public string $redirectUri,
        public array $providers,
        public bool $enabled,
    ) {}

    public static function fromConfig(): self
    {
        $enabled = (bool) config('services.auth0.enabled', false);

        if (! $enabled) {
            return new self('', '', '', '', [], false);
        }

        $domain = (string) config('services.auth0.domain');
        $clientId = (string) config('services.auth0.client_id');
        $clientSecret = (string) config('services.auth0.client_secret');
        $redirectUri = (string) config('services.auth0.redirect_uri');

        if ($domain === '' || $clientId === '' || $clientSecret === '' || $redirectUri === '') {
            throw new InvalidArgumentException('Auth0 is enabled but missing required configuration.');
        }

        $providers = array_filter(
            array_map(
                fn (string $value) => SocialIdentityProvider::tryFrom($value),
                config('services.auth0.providers', [])
            )
        );

        return new self($domain, $clientId, $clientSecret, $redirectUri, array_values($providers), true);
    }

    public function getAuthorizeUrl(): string
    {
        return sprintf('https://%s/authorize', $this->domain);
    }

    public function getTokenUrl(): string
    {
        return sprintf('https://%s/oauth/token', $this->domain);
    }

    public function getUserInfoUrl(): string
    {
        return sprintf('https://%s/userinfo', $this->domain);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
