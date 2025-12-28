<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Models\BusinessHoursException;
use App\Models\BusinessHoursSchedule;
use App\Models\BusinessHoursScheduleDay;
use App\Models\BusinessHoursTimeRange;
use App\Models\Organization;
use App\Observers\BusinessHoursExceptionCacheObserver;
use App\Observers\BusinessHoursScheduleDayCacheObserver;
use App\Observers\BusinessHoursTimeRangeCacheObserver;
use App\Services\VoiceRouting\VoiceRoutingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for Business Hours Related Models Cache Observers
 *
 * Tests automatic cache invalidation when nested business hours models are modified.
 * Phase 1 Step 8.6: Cache Invalidation for Related Models
 */
class BusinessHoursRelatedModelsCacheObserverTest extends TestCase
{
    use RefreshDatabase;

    private VoiceRoutingCacheService $cacheService;
    private Organization $organization;
    private BusinessHoursSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheService = app(VoiceRoutingCacheService::class);
        $this->organization = Organization::factory()->create();
        $this->schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Clear cache before each test
        Cache::flush();
    }

    /**
     * Test cache is invalidated when schedule day is created
     */
    public function test_cache_invalidated_when_schedule_day_created(): void
    {
        // Arrange - create a schedule without default days
        $schedule2 = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Delete auto-created days so we can test creation
        $schedule2->scheduleDays()->delete();

        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - create a new schedule day
        BusinessHoursScheduleDay::create([
            'business_hours_schedule_id' => $schedule2->id,
            'day_of_week' => \App\Enums\DayOfWeek::MONDAY,
            'enabled' => true,
        ]);

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache is invalidated when schedule day is updated
     */
    public function test_cache_invalidated_when_schedule_day_updated(): void
    {
        // Arrange
        $scheduleDay = $this->schedule->scheduleDays()->first();
        $this->assertNotNull($scheduleDay);

        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - update the schedule day
        $scheduleDay->enabled = false;
        $scheduleDay->save();

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache is invalidated when schedule day is deleted
     */
    public function test_cache_invalidated_when_schedule_day_deleted(): void
    {
        // Arrange
        $scheduleDay = $this->schedule->scheduleDays()->first();
        $this->assertNotNull($scheduleDay);

        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - delete the schedule day
        $scheduleDay->delete();

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache is invalidated when time range is created
     */
    public function test_cache_invalidated_when_time_range_created(): void
    {
        // Arrange
        $scheduleDay = $this->schedule->scheduleDays()->first();
        $this->assertNotNull($scheduleDay);

        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - create a new time range
        BusinessHoursTimeRange::create([
            'business_hours_schedule_day_id' => $scheduleDay->id,
            'start_time' => '18:00',
            'end_time' => '22:00',
        ]);

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache is invalidated when time range is updated
     */
    public function test_cache_invalidated_when_time_range_updated(): void
    {
        // Arrange
        $scheduleDay = $this->schedule->scheduleDays()->first();
        $this->assertNotNull($scheduleDay);

        $timeRange = $scheduleDay->timeRanges()->first();
        $this->assertNotNull($timeRange);

        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - update the time range
        $timeRange->start_time = '08:00';
        $timeRange->end_time = '18:00';
        $timeRange->save();

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache is invalidated when time range is deleted
     */
    public function test_cache_invalidated_when_time_range_deleted(): void
    {
        // Arrange
        $scheduleDay = $this->schedule->scheduleDays()->first();
        $this->assertNotNull($scheduleDay);

        $timeRange = $scheduleDay->timeRanges()->first();
        $this->assertNotNull($timeRange);

        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - delete the time range
        $timeRange->delete();

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache is invalidated when exception is created
     */
    public function test_cache_invalidated_when_exception_created(): void
    {
        // Arrange
        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - create a new exception
        BusinessHoursException::create([
            'business_hours_schedule_id' => $this->schedule->id,
            'date' => '2025-12-25',
            'name' => 'Christmas Day',
            'type' => \App\Enums\BusinessHoursExceptionType::CLOSED,
        ]);

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache is invalidated when exception is updated
     */
    public function test_cache_invalidated_when_exception_updated(): void
    {
        // Arrange
        $exception = BusinessHoursException::create([
            'business_hours_schedule_id' => $this->schedule->id,
            'date' => '2025-12-25',
            'name' => 'Christmas Day',
            'type' => \App\Enums\BusinessHoursExceptionType::CLOSED,
        ]);

        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - update the exception
        $exception->name = 'Christmas Holiday';
        $exception->save();

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache is invalidated when exception is deleted
     */
    public function test_cache_invalidated_when_exception_deleted(): void
    {
        // Arrange
        $exception = BusinessHoursException::create([
            'business_hours_schedule_id' => $this->schedule->id,
            'date' => '2025-12-25',
            'name' => 'Christmas Day',
            'type' => \App\Enums\BusinessHoursExceptionType::CLOSED,
        ]);

        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - delete the exception
        $exception->delete();

        // Assert - cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test cache isolation between organizations for schedule days
     */
    public function test_cache_isolation_between_organizations_for_schedule_days(): void
    {
        // Arrange - create second organization with schedule
        $org2 = Organization::factory()->create();
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

        // Act - modify schedule day in first organization
        $scheduleDay = $this->schedule->scheduleDays()->first();
        $scheduleDay->enabled = false;
        $scheduleDay->save();

        // Assert - only first organization's cache should be invalidated
        $this->assertFalse(Cache::has($cacheKey1));
        $this->assertTrue(Cache::has($cacheKey2));
    }

    /**
     * Test multiple nested changes trigger invalidations
     */
    public function test_multiple_nested_changes_trigger_invalidations(): void
    {
        // Arrange
        $scheduleDay = $this->schedule->scheduleDays()->first();
        $timeRange = $scheduleDay->timeRanges()->first();

        $cacheKey = "routing:business_hours:{$this->organization->id}";

        // Test schedule day change
        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $this->assertTrue(Cache::has($cacheKey));

        $scheduleDay->enabled = !$scheduleDay->enabled;
        $scheduleDay->save();
        $this->assertFalse(Cache::has($cacheKey));

        // Test time range change
        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $this->assertTrue(Cache::has($cacheKey));

        $timeRange->start_time = '10:00';
        $timeRange->save();
        $this->assertFalse(Cache::has($cacheKey));

        // Test exception change
        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $this->assertTrue(Cache::has($cacheKey));

        BusinessHoursException::create([
            'business_hours_schedule_id' => $this->schedule->id,
            'date' => '2025-01-01',
            'name' => 'New Year',
            'type' => \App\Enums\BusinessHoursExceptionType::CLOSED,
        ]);
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test observer properly traverses relationship chain for time ranges
     */
    public function test_observer_traverses_relationship_chain_for_time_ranges(): void
    {
        // Arrange
        $scheduleDay = $this->schedule->scheduleDays()->first();
        $timeRange = $scheduleDay->timeRanges()->first();

        // Warm cache
        $cached = $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $this->assertNotNull($cached);

        // Get original time range
        $originalStartTime = $timeRange->start_time;

        // Act - update time range (deep in the relationship chain)
        $timeRange->start_time = '10:00';
        $timeRange->save();

        // Re-fetch from cache (should miss and load from DB)
        $refreshed = $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $refreshedTimeRange = $refreshed->scheduleDays
            ->firstWhere('id', $scheduleDay->id)
            ->timeRanges
            ->firstWhere('id', $timeRange->id);

        // Assert - should have new time from database
        $this->assertNotEquals($originalStartTime, $refreshedTimeRange->start_time);
        $this->assertEquals('10:00', $refreshedTimeRange->start_time);
    }

    /**
     * Test observers are injected with correct service
     */
    public function test_observers_are_injected_with_cache_service(): void
    {
        // Arrange & Act
        $scheduleDayObserver = app(BusinessHoursScheduleDayCacheObserver::class);
        $timeRangeObserver = app(BusinessHoursTimeRangeCacheObserver::class);
        $exceptionObserver = app(BusinessHoursExceptionCacheObserver::class);

        // Assert - observers should be properly instantiated
        $this->assertInstanceOf(BusinessHoursScheduleDayCacheObserver::class, $scheduleDayObserver);
        $this->assertInstanceOf(BusinessHoursTimeRangeCacheObserver::class, $timeRangeObserver);
        $this->assertInstanceOf(BusinessHoursExceptionCacheObserver::class, $exceptionObserver);
    }

    /**
     * Test cache invalidation works when parent relationships are loaded
     */
    public function test_cache_invalidation_works_with_eager_loading(): void
    {
        // Arrange
        $this->cacheService->getActiveBusinessHoursSchedule($this->organization->id);
        $cacheKey = "routing:business_hours:{$this->organization->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act - update time range with eager loaded relationships
        $timeRange = BusinessHoursTimeRange::with('scheduleDay.schedule')->first();
        $timeRange->start_time = '11:00';
        $timeRange->save();

        // Assert - cache should be invalidated even with eager loading
        $this->assertFalse(Cache::has($cacheKey));
    }
}
