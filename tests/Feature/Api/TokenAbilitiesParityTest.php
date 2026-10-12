<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\SocialIdentityProvider;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserSocialIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TokenAbilitiesParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: UserRole, 1: bool}>
     */
    public static function roles(): array
    {
        $cases = [];
        foreach (UserRole::cases() as $role) {
            $cases[$role->value] = [$role, false];
            $cases[$role->value.' + platform manager'] = [$role, true];
        }

        return $cases;
    }

    #[DataProvider('roles')]
    public function test_password_and_auth0_login_issue_the_same_abilities(UserRole $role, bool $platformManager): void
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'is_platform_manager' => $platformManager,
        ]);

        $passwordLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], ['X-Auth-Mode' => 'token'])->assertOk();

        $expected = $role->tokenAbilities($platformManager);
        $this->assertSame($expected, PersonalAccessToken::findToken($passwordLogin->json('access_token'))->abilities);

        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'id');
        Config::set('services.auth0.client_secret', 'secret');
        Config::set('services.auth0.redirect_uri', 'https://app.opbx.com/ui/auth/callback');
        Config::set('services.auth0.providers', ['google']);

        $identity = UserSocialIdentity::factory()->create([
            'user_id' => $user->id,
            'provider' => SocialIdentityProvider::GOOGLE,
            'provider_subject' => 'google-oauth2|123',
        ]);
        $state = $this->postJson('/api/v1/auth/auth0/redirect', ['provider' => 'google', 'intent' => 'login'])->json('state');
        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => $identity->provider_email,
                'email_verified' => true,
                'name' => 'Test User',
            ], 200),
        ]);

        $auth0Login = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}")->assertOk();

        $this->assertSame($expected, PersonalAccessToken::findToken($auth0Login->json('access_token'))->abilities);
    }
}
