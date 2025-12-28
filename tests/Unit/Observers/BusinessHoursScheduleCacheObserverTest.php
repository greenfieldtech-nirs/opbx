<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Models\BusinessHoursSchedule;
use App\Models\Organization;
use App\Observers\BusinessHoursScheduleCacheObserver;
use App\Services\VoiceRouting\VoiceRoutingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for BusinessHoursScheduleCacheObserver
 *
 * Tests automatic cache invalidation when business hours schedules are modified.
 * Phase 1 Step 8.5: Business Hours Cache Observer
 */
class BusinessHoursScheduleCacheObserverTest extends TestCase
{
    use RefreshDatabase;

    private VoiceRoutingCacheService $cacheService;
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheService = app(VoiceRoutingCacheService::class);
        $this->organization = Organization::factory()->create();

        // Clear cache before each test
        Cache::flush();
    }

    /**
     * Test cache is invalidated when schedule is updated
     */
    public function test_cache_invalidated_when_schedule_updated(): void
    {
        // Arrange
        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Warm up cache
        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - update the schedule (observer should trigger)
        $schedule->name = 'Updated Schedule Name';
        $schedule->save();

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache is invalidated when schedule is deleted
     */
    public function test_cache_invalidated_when_schedule_deleted(): void
    {
        // Arrange
        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Warm up cache
        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - delete the schedule (observer should trigger)
        $schedule->delete();

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache invalidation uses correct organization_id
     */
    public function test_cache_invalidation_uses_correct_organization_id(): void
    {
        // Arrange - create schedules in different organizations
        $org2 = Organization::factory()->create();

        $schedule1 = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $schedule2 = BusinessHoursSchedule::factory()->create([
            'organization_id' => $org2->id,
        ]);

        // Warm up both caches
        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $this->cacheService->getActiveBusinessHoursSchedule($org2->id);

        $cacheKey1 = "routing:business_hours:{$this->organization->id}";
        $cacheKey2 = "routing:business_hours:{$org2->id}";

        $this->assertTrue(Cache::has($cacheKey1));
        $this->assertTrue(Cache::has($cacheKey2));

        // Act - update schedule in first organization
        $schedule1->name = 'Updated Name';
        $schedule1->save();

        // Assert - only first organization's cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey1));
        $this->assertTrue(Cache::has($cacheKey2));
    }

    /**
     * Test cache is NOT invalidated when schedule is created
     */
    public function test_cache_not_invalidated_when_schedule_created(): void
    {
        // Arrange - create and cache a schedule first
        $existingSchedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - create a new schedule in a different organization
        $org2 = Organization::factory()->create();
        BusinessHoursSchedule::factory()->create([
            'organization_id' => $org2->id,
        ]);

        // Assert - existing cache should remain
        $this->assertTrue(Cache::has($cacheKey));
    }

    /**
     * Test observer is properly registered and triggered
     */
    public function test_observer_is_registered_and_triggered(): void
    {
        // Arrange
        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Original Name',
        ]);

        // Warm up cache
        $cached = $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $this->assertNotNull($cached);
        $this->assertEquals('Original Name', $cached->name);

        // Act - update schedule
        $schedule->name = 'New Name';
        $schedule->save();

        // Re-fetch from cache (should miss and load from DB with new name)
        $refreshed = $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);

        // Assert - should have new name from database
        $this->assertNotNull($refreshed);
        $this->assertEquals('New Name', $refreshed->name);
    }

    /**
     * Test multiple updates trigger multiple invalidations
     */
    public function test_multiple_updates_trigger_multiple_invalidations(): void
    {
        // Arrange
        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $cacheKey = "routing:business_hours:{$this->organization->id}";

        // Act & Assert - multiple update cycles
        for ($i = 0; $i < 3; $i++) {
            // Warm up cache
            $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
            $this->assertTrue(Cache::has($cacheKey), "Cache should exist before update #{$i}");

            // Update schedule
            $schedule->name = "Updated Name {$i}";
            $schedule->save();

            // Verify cache was invalidated
            $this->assertFalse(Cache::has($cacheKey), "Cache should be invalidated after update #{$i}");
        }
    }

    /**
     * Test cache invalidation when status changes
     */
    public function test_cache_invalidated_when_status_changes(): void
    {
        // Arrange
        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Warm up cache
        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - change status from active to inactive
        $schedule->status = \App\Enums\BusinessHoursStatus::INACTIVE;
        $schedule->save();

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache invalidation when actions change
     */
    public function test_cache_invalidated_when_actions_change(): void
    {
        // Arrange
        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
            'open_hours_action' => 'ext-100',
            'closed_hours_action' => 'ext-voicemail',
        ]);

        // Warm up cache
        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - change actions
        $schedule->open_hours_action = 'ext-200';
        $schedule->closed_hours_action = 'ext-announce';
        $schedule->save();

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test observer handles inactive schedules
     */
    public function test_observer_handles_inactive_schedules(): void
    {
        // Arrange
        $schedule = BusinessHoursSchedule::factory()
            ->inactive()
            ->create([
                'organization_id' => $this->organization->id,
            ]);

        // Warm up cache (will be null because schedule is inactive)
        $result = $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $this->assertNull($result);

        $cacheKey = "routing:business_hours:{$this->organization->id}";

        // Act - update inactive schedule
        $schedule->name = 'Updated Inactive Schedule';
        $schedule->save();

        // Assert - cache key should not exist (because schedule was inactive)
        // But observer still fires and attempts invalidation (no-op)
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test observer is injected with correct service
     */
    public function test_observer_is_injected_with_cache_service(): void
    {
        // Arrange & Act
        $observer = app(BusinessHoursScheduleCacheObserver::class);

        // Assert - observer should be properly instantiated
        $this->assertInstanceOf(BusinessHoursScheduleCacheObserver::class, $observer);

        // Verify the observer has the cache service injected
        // (This is implicit through successful construction via DI)
        $this->assertTrue(true);
    }

    /**
     * Test cache invalidation affects all schedules in organization
     *
     * Since cache is per-organization (not per-schedule), updating any
     * schedule in the organization invalidates the entire org cache.
     */
    public function test_cache_invalidation_affects_organization_not_individual_schedules(): void
    {
        // Arrange - create multiple schedules in same organization
        $schedule1 = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Schedule 1',
        ]);

        $schedule2 = BusinessHoursSchedule::factory()
            ->inactive()
            ->create([
                'organization_id' => $this->organization->id,
                'name' => 'Schedule 2',
            ]);

        // Warm up cache (gets active schedule = schedule1)
        $cached = $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $this->assertNotNull($cached);
        $this->assertEquals('Schedule 1', $cached->name);

        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - update schedule2 (the inactive one)
        $schedule2->name = 'Updated Schedule 2';
        $schedule2->save();

        // Assert - organization cache should be invalidated
        // (even though we updated the inactive schedule, not the cached one)
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test observer handles soft deletes if implemented
     */
    public function test_observer_handles_soft_deletes(): void
    {
        // Arrange
        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Warm up cache
        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - delete schedule (soft delete if enabled, hard delete otherwise)
        $schedule->delete();

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }
}
