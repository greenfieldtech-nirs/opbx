<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\SocialIdentityProvider;
use Tests\TestCase;

class SocialIdentityProviderTest extends TestCase
{
    public function test_google_maps_to_auth0_connection(): void
    {
        $this->assertSame('google-oauth2', SocialIdentityProvider::GOOGLE->auth0Connection());
    }

    public function test_x_maps_to_twitter_connection(): void
    {
        $this->assertSame('twitter', SocialIdentityProvider::X->auth0Connection());
    }
}
