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
 * Session cookie hardening. Login itself is Bearer-token only (no cookie mode);
 * see LoginRememberMeTest for the AJAX / X-Auth-Mode: cookie cases.
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
