<?php

declare(strict_types=1);

namespace App\Services\Auth0;

use App\Enums\SocialIdentityProvider;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class Auth0Service
{
    public function __construct(
        private readonly Auth0Config $config,
        private readonly Auth0StateStore $stateStore,
        private readonly Auth0ProfileNormalizer $normalizer,
    ) {}

    public function buildAuthorizeUrl(string $provider, string $intent, ?int $userId = null): array
    {
        $socialProvider = SocialIdentityProvider::tryFrom($provider);

        if ($socialProvider === null || ! $this->isProviderEnabled($socialProvider)) {
            throw new InvalidArgumentException("Unsupported or disabled provider: {$provider}");
        }

        $state = $this->stateStore->create($provider, $intent, $userId);
        $codeChallenge = $this->base64UrlEncode(hash('sha256', $state->codeVerifier, true));

        $url = $this->config->getAuthorizeUrl().'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'scope' => 'openid profile email',
            'state' => $state->state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'connection' => $socialProvider->auth0Connection(),
        ]);

        return ['url' => $url, 'state' => $state->state];
    }

    /**
     * @return array<string, mixed>
     */
    public function handleCallback(string $code, string $state): array
    {
        $stored = $this->stateStore->consume($state);

        $tokenResponse = Http::timeout(30)->asForm()->post($this->config->getTokenUrl(), [
            'grant_type' => 'authorization_code',
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->config->redirectUri,
            'code_verifier' => $stored->codeVerifier,
        ]);

        if ($tokenResponse->failed()) {
            throw new RuntimeException('Auth0 token exchange failed: '.$tokenResponse->body());
        }

        $accessToken = $tokenResponse->json('access_token');

        $userInfoResponse = Http::timeout(30)
            ->withToken($accessToken)
            ->get($this->config->getUserInfoUrl());

        if ($userInfoResponse->failed()) {
            throw new RuntimeException('Auth0 userinfo request failed: '.$userInfoResponse->body());
        }

        $provider = SocialIdentityProvider::tryFrom($stored->payload['provider']);

        if ($provider === null) {
            throw new RuntimeException('Invalid provider in state.');
        }

        return [
            ...$this->normalizer->normalize($provider, $userInfoResponse->json()),
            'intent' => $stored->payload['intent'],
            'user_id' => $stored->payload['user_id'] ?? null,
        ];
    }

    private function isProviderEnabled(SocialIdentityProvider $provider): bool
    {
        return in_array($provider, $this->config->providers, true);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
