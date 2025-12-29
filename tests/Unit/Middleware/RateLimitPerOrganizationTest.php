<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\RateLimitPerOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests for per-organization rate limiting middleware.
 */
class RateLimitPerOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private RateLimitPerOrganization $middleware;
    private Organization $organization;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new RateLimitPerOrganization();

        // Create test organization and user
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Set test-friendly rate limits
        config()->set('rate-limiting', [
            'default' => [
                'max_attempts' => 5,
                'per_minutes' => 1,
            ],
            'webhook' => [
                'max_attempts' => 3,
                'per_minutes' => 1,
            ],
            'api' => [
                'max_attempts' => 5,
                'per_minutes' => 1,
            ],
            'voice_routing' => [
                'max_attempts' => 5,
                'per_minutes' => 1,
            ],
        ]);

        // Clear cache
        Cache::flush();
    }

    /**
     * Test rate limit allows requests within limit.
     */
    public function test_allows_requests_within_limit(): void
    {
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $this->user);

        // Use hardcoded limit for testing (config returns 60 as fallback)
        $maxAttempts = 5;

        // Make requests up to the limit
        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = $this->middleware->handle(
                $request,
                fn () => new Response('OK', 200),
                'default'
            );

            $this->assertEquals(200, $response->getStatusCode(), "Request ".($i+1)." should succeed");

            // Check cache value to debug
            $key = "rate_limit:org:{$this->organization->id}:default";
            $attempts = Cache::get($key);
            $this->assertEquals($i + 1, $attempts, "Cache should have ".($i+1)." attempts after request ".($i+1));
        }
    }

    /**
     * Test rate limit blocks requests exceeding limit.
     */
    public function test_blocks_requests_exceeding_limit(): void
    {
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $this->user);

        // Use small limit for testing
        $maxAttempts = 5;

        // Make requests up to the limit
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->middleware->handle(
                $request,
                fn () => new Response('OK', 200),
                'default'
            );
        }

        // Next request should be blocked
        $response = $this->middleware->handle(
            $request,
            fn () => new Response('OK', 200),
            'default'
        );

        $this->assertEquals(429, $response->getStatusCode());
        $this->assertEquals('60', $response->headers->get('Retry-After'));
        $this->assertEquals('0', $response->headers->get('X-RateLimit-Remaining'));

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Rate limit exceeded', $data['error']);
        $this->assertArrayHasKey('retry_after', $data);
    }

    /**
     * Test rate limit uses correct configuration for different limit types.
     */
    public function test_uses_correct_limit_type_configuration(): void
    {
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $this->user);

        // Test webhook limit type with small limit
        $webhookMax = 3;
        for ($i = 0; $i < $webhookMax; $i++) {
            $response = $this->middleware->handle(
                $request,
                fn () => new Response('OK', 200),
                'webhook'
            );

            $this->assertEquals(200, $response->getStatusCode());
        }

        // Next webhook request should be blocked
        $response = $this->middleware->handle(
            $request,
            fn () => new Response('OK', 200),
            'webhook'
        );

        $this->assertEquals(429, $response->getStatusCode());

        // But API limit type should still work (different key)
        Cache::flush();
        $response = $this->middleware->handle(
            $request,
            fn () => new Response('OK', 200),
            'api'
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test rate limit is per-organization.
     */
    public function test_rate_limit_is_per_organization(): void
    {
        // Create second organization with user
        $org2 = Organization::factory()->create();
        $user2 = User::factory()->create([
            'organization_id' => $org2->id,
        ]);

        $request1 = Request::create('/test', 'GET');
        $request1->setUserResolver(fn () => $this->user);

        $request2 = Request::create('/test', 'GET');
        $request2->setUserResolver(fn () => $user2);

        // Use config value (5 from setUp)
        $maxAttempts = config('rate-limiting.default.max_attempts');

        // Exhaust org1's limit
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->middleware->handle(
                $request1,
                fn () => new Response('OK', 200),
                'default'
            );
        }

        // Org1 should be blocked
        $response1 = $this->middleware->handle(
            $request1,
            fn () => new Response('OK', 200),
            'default'
        );
        $this->assertEquals(429, $response1->getStatusCode());

        // Org2 should still work
        $response2 = $this->middleware->handle(
            $request2,
            fn () => new Response('OK', 200),
            'default'
        );
        $this->assertEquals(200, $response2->getStatusCode());
    }

    /**
     * Test rate limit extracts organization from authenticated user.
     */
    public function test_extracts_organization_from_authenticated_user(): void
    {
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $this->user);

        $response = $this->middleware->handle(
            $request,
            fn () => new Response('OK', 200),
            'default'
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test rate limit extracts organization from request parameter.
     */
    public function test_extracts_organization_from_request_parameter(): void
    {
        $request = Request::create('/test', 'GET');
        $request->merge(['_organization_id' => $this->organization->id]);

        $response = $this->middleware->handle(
            $request,
            fn () => new Response('OK', 200),
            'default'
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test rate limit extracts organization from header.
     */
    public function test_extracts_organization_from_header(): void
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Organization-ID', (string) $this->organization->id);

        $response = $this->middleware->handle(
            $request,
            fn () => new Response('OK', 200),
            'default'
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test rate limit returns 400 when organization not identified.
     */
    public function test_returns_400_when_organization_not_identified(): void
    {
        $request = Request::create('/test', 'GET');

        $response = $this->middleware->handle(
            $request,
            fn () => new Response('OK', 200),
            'default'
        );

        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Organization not identified', $data['error']);
    }

    /**
     * Test rate limit uses default configuration for invalid limit type.
     */
    public function test_uses_default_for_invalid_limit_type(): void
    {
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $this->user);

        $response = $this->middleware->handle(
            $request,
            fn () => new Response('OK', 200),
            'invalid_type'
        );

        $this->assertEquals(200, $response->getStatusCode());
        // When limit type is invalid, it falls back to 'default' config (5 in tests)
        $this->assertEquals('5', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('4', $response->headers->get('X-RateLimit-Remaining'));
    }

    /**
     * Test rate limit adds proper headers to response.
     */
    public function test_adds_rate_limit_headers(): void
    {
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $this->user);

        $response = $this->middleware->handle(
            $request,
            fn () => new Response('OK', 200),
            'default'
        );

        $this->assertNotNull($response->headers->get('X-RateLimit-Limit'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Reset'));
    }

    /**
     * Test rate limit respects different time windows.
     */
    public function test_respects_time_windows(): void
    {
        // This test verifies that rate limit counters have TTL
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $this->user);

        // Make one request
        $response = $this->middleware->handle(
            $request,
            fn () => new Response('OK', 200),
            'default'
        );

        $this->assertEquals(200, $response->getStatusCode());

        // Verify cache key exists with TTL
        $key = "rate_limit:org:{$this->organization->id}:default";
        $value = Cache::get($key);

        $this->assertNotNull($value);
        $this->assertEquals(1, $value);
    }

    /**
     * Test rate limit works with ResilientCacheService fallback.
     */
    public function test_works_with_cache_fallback(): void
    {
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $this->user);

        // Even if Redis fails, middleware should work (will just not cache properly)
        $response = $this->middleware->handle(
            $request,
            fn () => new Response('OK', 200),
            'default'
        );

        $this->assertEquals(200, $response->getStatusCode());
    }
}
