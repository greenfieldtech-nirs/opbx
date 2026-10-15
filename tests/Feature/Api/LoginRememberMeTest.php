<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * "Remember me" issues a 30-day token; a normal login keeps the 24-hour token.
 */
final class LoginRememberMeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['status' => 'active']);
        User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'remember@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::PBX_ADMIN,
            'status' => 'active',
        ]);
    }

    private function login(array $extra = []): string
    {
        $response = $this->postJson('/api/v1/auth/login', ['email' => 'remember@example.com', 'password' => 'password123'] + $extra);
        $response->assertOk();

        return $response->json('access_token');
    }

    private function hoursUntilExpiry(string $plainToken): int
    {
        return (int) round(now()->diffInHours(PersonalAccessToken::findToken($plainToken)->expires_at));
    }

    public function test_login_without_remember_me_issues_24_hour_token(): void
    {
        $token = $this->login();

        $this->assertSame(24, $this->hoursUntilExpiry($token));
        $this->postJson('/api/v1/auth/login', ['email' => 'remember@example.com', 'password' => 'password123', 'remember' => false])
            ->assertJsonPath('expires_in', 86400);
    }

    public function test_login_with_remember_me_issues_30_day_token(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'remember@example.com', 'password' => 'password123', 'remember' => true])
            ->assertOk()
            ->assertJsonPath('expires_in', 30 * 86400);

        $this->assertSame(30 * 24, $this->hoursUntilExpiry($this->login(['remember' => true])));
    }

    public function test_remember_me_token_outlives_a_normal_token(): void
    {
        $normal = $this->login();
        $remembered = $this->login(['remember' => true]);
        // A new login revokes earlier tokens, so check each one on its own.
        $this->assertNull(PersonalAccessToken::findToken($normal));

        $this->travel(25)->hours();
        $this->withToken($remembered)->getJson('/api/v1/auth/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $normal = $this->login();
        $this->travel(25)->hours();
        $this->app['auth']->forgetGuards();
        $this->withToken($normal)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_refresh_keeps_the_remember_me_lifetime(): void
    {
        $remembered = $this->login(['remember' => true]);

        $response = $this->withToken($remembered)->postJson('/api/v1/auth/refresh')->assertOk();

        $response->assertJsonPath('expires_in', 30 * 86400);
        $this->assertSame(30 * 24, $this->hoursUntilExpiry($response->json('access_token')));
    }

    public function test_remember_must_be_boolean(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'remember@example.com', 'password' => 'password123', 'remember' => 'yes please'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['remember']);
    }

    public function test_login_always_returns_a_bearer_token_even_for_ajax_or_cookie_requests(): void
    {
        foreach ([['X-Requested-With' => 'XMLHttpRequest'], ['X-Auth-Mode' => 'cookie']] as $headers) {
            $this->withHeaders($headers)
                ->postJson('/api/v1/auth/login', ['email' => 'remember@example.com', 'password' => 'password123'])
                ->assertOk()
                ->assertJsonPath('token_type', 'Bearer')
                ->assertJsonStructure(['access_token', 'expires_in', 'user']);
        }
    }
}
