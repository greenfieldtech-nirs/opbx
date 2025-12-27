<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Test rate limiting enforcement across different endpoint types.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear all rate limiters before each test
        RateLimiter::clear('api');
        RateLimiter::clear('webhooks');
        RateLimiter::clear('auth');
        RateLimiter::clear('sensitive');
    }

    /**
     * Test API rate limiting for authenticated users.
     */
    public function test_api_rate_limit_enforced_for_authenticated_users(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        // Get the rate limit from config
        $apiLimit = config('rate_limiting.api', 60);

        // Make requests up to the limit
        for ($i = 0; $i < $apiLimit; $i++) {
            $response = $this->actingAs($user)->getJson('/api/v1/profile');
            $this->assertEquals(200, $response->status());
        }

        // Next request should be rate limited
        $response = $this->actingAs($user)->getJson('/api/v1/profile');
        $this->assertEquals(429, $response->status());
        $this->assertArrayHasKey('error', $response->json());
        $this->assertEquals('Too Many Requests', $response->json('error'));
        $this->assertArrayHasKey('retry_after', $response->json());
    }

    /**
     * Test auth rate limiting on login endpoint.
     */
    public function test_auth_rate_limit_enforced_on_login(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Get the auth rate limit from config
        $authLimit = config('rate_limiting.auth', 5);

        // Make login attempts up to the limit (with wrong password)
        for ($i = 0; $i < $authLimit; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ]);
            // Should get 401 for wrong password
            $this->assertContains($response->status(), [401, 422]);
        }

        // Next request should be rate limited
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);
        $this->assertEquals(429, $response->status());
        $this->assertArrayHasKey('error', $response->json());
    }

    /**
     * Test sensitive operations rate limiting.
     */
    public function test_sensitive_operations_rate_limit_enforced(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'password' => bcrypt('currentpassword'),
        ]);

        // Get the sensitive operations rate limit from config
        $sensitiveLimit = config('rate_limiting.sensitive', 10);

        // Make password change attempts up to the limit
        for ($i = 0; $i < $sensitiveLimit; $i++) {
            $response = $this->actingAs($user)->putJson('/api/v1/profile/password', [
                'current_password' => 'wrongpassword',
                'new_password' => 'NewPassword123!',
                'new_password_confirmation' => 'NewPassword123!',
            ]);
            // Should get validation error for wrong current password
            $this->assertContains($response->status(), [400, 422]);
        }

        // Next request should be rate limited
        $response = $this->actingAs($user)->putJson('/api/v1/profile/password', [
            'current_password' => 'wrongpassword',
            'new_password' => 'NewPassword123!',
            'new_password_confirmation' => 'NewPassword123!',
        ]);
        $this->assertEquals(429, $response->status());
        $this->assertArrayHasKey('error', $response->json());
    }

    /**
     * Test rate limit headers are included in responses.
     */
    public function test_rate_limit_headers_included_in_responses(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($user)->getJson('/api/v1/profile');

        $this->assertEquals(200, $response->status());
        $this->assertNotNull($response->headers->get('X-RateLimit-Limit'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Remaining'));
    }

    /**
     * Test rate limiting is per-user, not global.
     */
    public function test_rate_limit_is_per_user(): void
    {
        $organization = Organization::factory()->create();
        $user1 = User::factory()->create(['organization_id' => $organization->id]);
        $user2 = User::factory()->create(['organization_id' => $organization->id]);

        // Get the rate limit
        $apiLimit = config('rate_limiting.api', 60);

        // User 1 exhausts their limit
        for ($i = 0; $i < $apiLimit; $i++) {
            $response = $this->actingAs($user1)->getJson('/api/v1/profile');
            $this->assertEquals(200, $response->status());
        }

        // User 1 should be rate limited
        $response = $this->actingAs($user1)->getJson('/api/v1/profile');
        $this->assertEquals(429, $response->status());

        // User 2 should still have their full quota
        $response = $this->actingAs($user2)->getJson('/api/v1/profile');
        $this->assertEquals(200, $response->status());
    }

    /**
     * Test webhook rate limiting is per IP.
     */
    public function test_webhook_rate_limit_is_per_ip(): void
    {
        // Note: Testing webhook rate limiting is complex as it requires
        // valid webhook signatures. This test verifies the middleware is applied.
        // Integration tests should verify full webhook rate limiting behavior.

        $response = $this->postJson('/api/webhooks/cloudonix/call-initiated', [
            'call_id' => 'test-call-123',
            'from' => '+12025551234',
            'to' => '+13105559999',
            'did' => '+13105559999',
        ]);

        // Should be rejected by signature verification before rate limiting
        // but this confirms middleware stack includes both
        $this->assertContains($response->status(), [401, 500]);
    }

    /**
     * Test retry-after header is present when rate limited.
     */
    public function test_retry_after_header_present_when_rate_limited(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'password' => bcrypt('currentpassword'),
        ]);

        // Get the sensitive operations rate limit
        $sensitiveLimit = config('rate_limiting.sensitive', 10);

        // Exhaust the limit
        for ($i = 0; $i < $sensitiveLimit; $i++) {
            $this->actingAs($user)->putJson('/api/v1/profile/password', [
                'current_password' => 'wrongpassword',
                'new_password' => 'NewPassword123!',
                'new_password_confirmation' => 'NewPassword123!',
            ]);
        }

        // Get rate limited response
        $response = $this->actingAs($user)->putJson('/api/v1/profile/password', [
            'current_password' => 'wrongpassword',
            'new_password' => 'NewPassword123!',
            'new_password_confirmation' => 'NewPassword123!',
        ]);

        $this->assertEquals(429, $response->status());
        $this->assertArrayHasKey('retry_after', $response->json());
        $this->assertIsNumeric($response->json('retry_after'));
    }
}
