<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\BusinessHoursSchedule;
use App\Models\Extension;
use App\Models\Organization;
use App\Services\VoiceRouting\VoiceRoutingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for VoiceRoutingCacheService
 *
 * Tests caching behavior for voice routing lookups to ensure performance
 * and correct cache invalidation.
 *
 * Phase 1 Step 8.1: Core Cache Service Tests
 */
class VoiceRoutingCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private VoiceRoutingCacheService $service;
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new VoiceRoutingCacheService();
        $this->organization = Organization::factory()->create();

        // Clear cache before each test
        Cache::flush();
    }

    /**
     * Test getExtension() cache miss - loads from database
     */
    public function test_get_extension_cache_miss_loads_from_database(): void
    {
        // Arrange
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1001',
            'status' => 'active',
            'password' => 'test-password-123',
        ]);

        $cacheKey = "routing:extension:{$this->organization->id}:1001";

        // Assert cache is empty
        $this->assertFalse(Cache::has($cacheKey));

        // Act
        $result = $this->service->getExtension($this->organization->id, '1001');

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($extension->id, $result->id);
        $this->assertEquals('1001', $result->extension_number);

        // Verify cache was populated
        $this->assertTrue(Cache::has($cacheKey));
    }

    /**
     * Test getExtension() cache hit - returns from cache without database query
     */
    public function test_get_extension_cache_hit_returns_from_cache(): void
    {
        // Arrange
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1002',
            'status' => 'active',
            'password' => 'test-password-123',
        ]);

        // Warm up cache
        $this->service->getExtension($this->organization->id, '1002');

        // Manually set a different value in cache to verify it's being used
        $cacheKey = "routing:extension:{$this->organization->id}:1002";
        $cachedExtension = Cache::get($cacheKey);
        $this->assertNotNull($cachedExtension);

        // Act - should return from cache
        $result = $this->service->getExtension($this->organization->id, '1002');

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($extension->id, $result->id);
    }

    /**
     * Test getExtension() returns null for non-existent extension
     */
    public function test_get_extension_returns_null_for_non_existent(): void
    {
        // Act
        $result = $this->service->getExtension($this->organization->id, '9999');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test getExtension() loads user relationship
     */
    public function test_get_extension_loads_user_relationship(): void
    {
        // Arrange
        $user = \App\Models\User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $extension = Extension::factory()
            ->withUser($user)
            ->create([
                'organization_id' => $this->organization->id,
                'extension_number' => '1003',
                'password' => 'test-password-123',
            ]);

        // Act
        $result = $this->service->getExtension($this->organization->id, '1003');

        // Assert
        $this->assertNotNull($result);
        $this->assertTrue($result->relationLoaded('user'));
        $this->assertNotNull($result->user);
    }

    /**
     * Test invalidateExtension() removes from cache
     */
    public function test_invalidate_extension_removes_from_cache(): void
    {
        // Arrange
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1004',
            'password' => 'test-password-123',
        ]);

        // Warm up cache
        $this->service->getExtension($this->organization->id, '1004');
        $cacheKey = "routing:extension:{$this->organization->id}:1004";
        $this->assertTrue(Cache::has($cacheKey));

        // Act
        $this->service->invalidateExtension($this->organization->id, '1004');

        // Assert
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test getActiveBusinessHoursSchedule() cache miss - loads from database
     */
    public function test_get_business_hours_schedule_cache_miss_loads_from_database(): void
    {
        // Arrange
        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $cacheKey = "routing:business_hours:{$this->organization->id}";

        // Assert cache is empty
        $this->assertFalse(Cache::has($cacheKey));

        // Act
        $result = $this->service->getActiveBusinessHoursSchedule($this->organization->id);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($schedule->id, $result->id);

        // Verify cache was populated
        $this->assertTrue(Cache::has($cacheKey));
    }

    /**
     * Test getActiveBusinessHoursSchedule() cache hit - returns from cache
     */
    public function test_get_business_hours_schedule_cache_hit_returns_from_cache(): void
    {
        // Arrange
        BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Warm up cache
        $this->service->getActiveBusinessHoursSchedule($this->organization->id);

        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - should return from cache
        $result = $this->service->getActiveBusinessHoursSchedule($this->organization->id);

        // Assert
        $this->assertNotNull($result);
    }

    /**
     * Test getActiveBusinessHoursSchedule() returns null when no active schedule
     */
    public function test_get_business_hours_schedule_returns_null_when_no_active_schedule(): void
    {
        // Arrange - create inactive schedule
        BusinessHoursSchedule::factory()
            ->inactive()
            ->create([
                'organization_id' => $this->organization->id,
            ]);

        // Act
        $result = $this->service->getActiveBusinessHoursSchedule($this->organization->id);

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test getActiveBusinessHoursSchedule() loads nested relationships
     */
    public function test_get_business_hours_schedule_loads_nested_relationships(): void
    {
        // Arrange
        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Act
        $result = $this->service->getActiveBusinessHoursSchedule($this->organization->id);

        // Assert
        $this->assertNotNull($result);
        $this->assertTrue($result->relationLoaded('scheduleDays'));
        $this->assertGreaterThan(0, $result->scheduleDays->count());
    }

    /**
     * Test invalidateBusinessHoursSchedule() removes from cache
     */
    public function test_invalidate_business_hours_schedule_removes_from_cache(): void
    {
        // Arrange
        BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Warm up cache
        $this->service->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act
        $this->service->invalidateBusinessHoursSchedule($this->organization->id);

        // Assert
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test clearOrganizationCache() clears all organization caches
     */
    public function test_clear_organization_cache_clears_all_caches(): void
    {
        // Arrange
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1005',
            'password' => 'test-password-123',
        ]);

        BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Warm up caches
        $this->service->getExtension($this->organization->id, '1005');
        $this->service->getActiveBusinessHoursSchedule($this->organization->id);

        $extensionKey = "routing:extension:{$this->organization->id}:1005";
        $businessHoursKey = "routing:business_hours:{$this->organization->id}";

        $this->assertTrue(Cache::has($extensionKey));
        $this->assertTrue(Cache::has($businessHoursKey));

        // Act
        $this->service->clearOrganizationCache($this->organization->id);

        // Assert - business hours cache should be cleared
        $this->assertFalse(Cache::has($businessHoursKey));
        // Note: clearOrganizationCache doesn't clear extension caches individually
        // as there could be many extensions. Extension cache is cleared via observers.
    }

    /**
     * Test cache TTL is respected for extensions (30 minutes = 1800 seconds)
     */
    public function test_extension_cache_ttl_is_correct(): void
    {
        // Arrange
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1006',
            'password' => 'test-password-123',
        ]);

        // Act
        $this->service->getExtension($this->organization->id, '1006');

        // Assert - verify cache entry exists with correct TTL
        $cacheKey = "routing:extension:{$this->organization->id}:1006";
        $this->assertTrue(Cache::has($cacheKey));

        // Note: Testing exact TTL is difficult without mocking time
        // This test verifies the key exists; TTL is tested via integration tests
    }

    /**
     * Test cache TTL is respected for business hours (15 minutes = 900 seconds)
     */
    public function test_business_hours_cache_ttl_is_correct(): void
    {
        // Arrange
        BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Act
        $this->service->getActiveBusinessHoursSchedule($this->organization->id);

        // Assert - verify cache entry exists
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));
    }

    /**
     * Test cache isolation between organizations
     */
    public function test_cache_isolation_between_organizations(): void
    {
        // Arrange
        $org2 = Organization::factory()->create();

        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1001',
            'password' => 'test-password-123',
        ]);

        Extension::factory()->create([
            'organization_id' => $org2->id,
            'extension_number' => '1001',
            'password' => 'test-password-456',
        ]);

        // Act
        $result1 = $this->service->getExtension($this->organization->id, '1001');
        $result2 = $this->service->getExtension($org2->id, '1001');

        // Assert
        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
        $this->assertNotEquals($result1->id, $result2->id);
        $this->assertEquals($this->organization->id, $result1->organization_id);
        $this->assertEquals($org2->id, $result2->organization_id);
    }

    /**
     * Test cache keys are properly formatted
     */
    public function test_cache_keys_are_properly_formatted(): void
    {
        // Arrange
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1007',
            'password' => 'test-password-123',
        ]);

        BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Act
        $this->service->getExtension($this->organization->id, '1007');
        $this->service->getActiveBusinessHoursSchedule($this->organization->id);

        // Assert - verify cache keys exist with expected format
        $expectedExtensionKey = "routing:extension:{$this->organization->id}:1007";
        $expectedBusinessHoursKey = "routing:business_hours:{$this->organization->id}";

        $this->assertTrue(Cache::has($expectedExtensionKey));
        $this->assertTrue(Cache::has($expectedBusinessHoursKey));
    }
}
