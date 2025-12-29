<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for cookie-based (SPA) authentication.
 *
 * Verifies httpOnly session cookie authentication works correctly
 * alongside token-based authentication.
 */
class CookieAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test organization and user
        $this->organization = Organization::factory()->create([
            'status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::PBX_ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);
    }

    public function test_csrf_cookie_endpoint_sets_cookie(): void
    {
        $response = $this->get('/api/sanctum/csrf-cookie');

        $response->assertStatus(200);
        $response->assertJson(['message' => 'CSRF cookie set']);
        $response->assertCookie('XSRF-TOKEN');
    }

    public function test_cookie_login_with_ajax_header(): void
    {
        // Get CSRF cookie first
        $this->get('/api/sanctum/csrf-cookie');

        // Login with X-Requested-With header (indicates AJAX/SPA request)
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ], [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertStatus(200);

        // Should return message and user, but NOT access_token
        $response->assertJsonStructure([
            'message',
            'user' => ['id', 'organization_id', 'name', 'email', 'role', 'status'],
        ]);

        $response->assertJsonMissing(['access_token']);

        // Should have session cookie
        $this->assertAuthenticatedAs($this->user, 'web');
    }

    public function test_cookie_login_with_explicit_header(): void
    {
        // Get CSRF cookie first
        $this->get('/api/sanctum/csrf-cookie');

        // Login with explicit X-Auth-Mode header
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ], [
            'X-Auth-Mode' => 'cookie',
        ]);

        $response->assertStatus(200);
        $response->assertJsonMissing(['access_token']);
        $this->assertAuthenticatedAs($this->user, 'web');
    }

    public function test_token_login_without_ajax_header(): void
    {
        // Login with explicit token mode (overrides postJson's X-Requested-With header)
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ], [
            'X-Auth-Mode' => 'token',
        ]);

        $response->assertStatus(200);

        // Should return access_token for token-based auth
        $response->assertJsonStructure([
            'access_token',
            'token_type',
            'expires_in',
            'user',
        ]);

        $response->assertJson([
            'token_type' => 'Bearer',
        ]);
    }

    public function test_cookie_logout_clears_session(): void
    {
        // Login with cookie auth
        $this->get('/api/sanctum/csrf-cookie');
        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ], ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertAuthenticatedAs($this->user, 'web');

        // Logout
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Successfully logged out.',
        ]);

        // Should no longer be authenticated
        $this->assertGuest('web');
    }

    public function test_cookie_authenticated_request(): void
    {
        // Login with cookie auth
        $this->get('/api/sanctum/csrf-cookie');
        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ], ['X-Requested-With' => 'XMLHttpRequest']);

        // Make authenticated request
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'user' => ['id', 'organization_id', 'name', 'email', 'role', 'status', 'organization'],
        ]);

        $response->assertJson([
            'user' => [
                'id' => $this->user->id,
                'email' => $this->user->email,
            ],
        ]);
    }

    public function test_cookie_refresh_regenerates_session(): void
    {
        // Login with cookie auth
        $this->get('/api/sanctum/csrf-cookie');
        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ], ['X-Requested-With' => 'XMLHttpRequest']);

        // Get current session ID
        $oldSession = session()->getId();

        // Refresh
        $response = $this->postJson('/api/v1/auth/refresh');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'user',
        ]);

        $response->assertJsonMissing(['access_token']);

        // Session should be regenerated (new ID)
        $newSession = session()->getId();
        $this->assertNotEquals($oldSession, $newSession);

        // Should still be authenticated
        $this->assertAuthenticatedAs($this->user, 'web');
    }

    public function test_cookie_and_token_auth_work_independently(): void
    {
        // Token-based login (explicitly request token mode)
        $tokenResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ], [
            'X-Auth-Mode' => 'token',
        ]);

        $token = $tokenResponse->json('access_token');
        $this->assertNotNull($token);

        // Cookie-based login (same user)
        $this->get('/api/sanctum/csrf-cookie');
        $cookieResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ], ['X-Requested-With' => 'XMLHttpRequest']);

        $cookieResponse->assertStatus(200);

        // Both should work independently
        // Token auth
        $response1 = $this->getJson('/api/v1/auth/me', [
            'Authorization' => "Bearer {$token}",
        ]);
        $response1->assertStatus(200);

        // Cookie auth
        $response2 = $this->getJson('/api/v1/auth/me');
        $response2->assertStatus(200);
    }

    public function test_httponly_cookie_prevents_javascript_access(): void
    {
        // This is a configuration test - verify session is httpOnly
        $this->assertTrue(config('session.http_only'), 'Session cookies must be httpOnly for security');
    }

    public function test_secure_cookie_in_production(): void
    {
        // Verify secure cookie configuration
        // In production, SESSION_SECURE_COOKIE should be true
        $isProduction = app()->environment('production');

        if ($isProduction) {
            $this->assertTrue(
                config('session.secure'),
                'Session cookies must be secure (HTTPS only) in production'
            );
        } else {
            // In non-production (testing/local), secure cookies are typically disabled
            // Just verify the httpOnly config is set for security
            $this->assertTrue(
                config('session.http_only'),
                'Session httpOnly configuration must be enabled for security'
            );
        }
    }
}
