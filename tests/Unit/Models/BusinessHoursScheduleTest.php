<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\BusinessHoursExceptionType;
use App\Enums\BusinessHoursStatus;
use App\Enums\DayOfWeek;
use App\Models\BusinessHoursException;
use App\Models\BusinessHoursExceptionTimeRange;
use App\Models\BusinessHoursSchedule;
use App\Models\BusinessHoursScheduleDay;
use App\Models\BusinessHoursTimeRange;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Business Hours Schedule model test suite.
 *
 * Tests the status calculation logic and time-based methods.
 */
class BusinessHoursScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    /**
     * Test that schedule correctly identifies open hours on a weekday.
     */
    public function test_is_currently_open_returns_true_during_open_hours(): void
    {
        $schedule = $this->createScheduleWithStandardHours();

        // Monday at 10:00 AM - should be open (schedule is 9-5)
        $testTime = Carbon::create(2025, 1, 6, 10, 0, 0); // Monday

        $this->assertTrue($schedule->isCurrentlyOpen($testTime));
    }

    /**
     * Test that schedule correctly identifies closed hours on a weekday.
     */
    public function test_is_currently_open_returns_false_outside_open_hours(): void
    {
        $schedule = $this->createScheduleWithStandardHours();

        // Monday at 6:00 PM - should be closed (schedule is 9-5)
        $testTime = Carbon::create(2025, 1, 6, 18, 0, 0); // Monday

        $this->assertFalse($schedule->isCurrentlyOpen($testTime));
    }

    /**
     * Test that schedule correctly identifies closed on weekends.
     */
    public function test_is_currently_open_returns_false_on_disabled_days(): void
    {
        $schedule = $this->createScheduleWithStandardHours();

        // Saturday at 10:00 AM - should be closed (weekend disabled)
        $testTime = Carbon::create(2025, 1, 11, 10, 0, 0); // Saturday

        $this->assertFalse($schedule->isCurrentlyOpen($testTime));
    }

    /**
     * Test that exceptions override normal schedule for closed days.
     */
    public function test_exceptions_override_normal_schedule_for_closed(): void
    {
        $schedule = $this->createScheduleWithStandardHours();

        // Add a holiday exception for Monday
        $exception = BusinessHoursException::create([
            'business_hours_schedule_id' => $schedule->id,
            'date' => '2025-01-06',
            'name' => 'Holiday',
            'type' => BusinessHoursExceptionType::CLOSED,
        ]);

        // Monday at 10:00 AM - should be closed due to exception
        $testTime = Carbon::create(2025, 1, 6, 10, 0, 0);

        $this->assertFalse($schedule->isCurrentlyOpen($testTime));
    }

    /**
     * Test that special hours exceptions work correctly.
     */
    public function test_special_hours_exceptions_work_correctly(): void
    {
        $schedule = $this->createScheduleWithStandardHours();

        // Add special hours exception for Monday (10-14 instead of 9-17)
        $exception = BusinessHoursException::create([
            'business_hours_schedule_id' => $schedule->id,
            'date' => '2025-01-06',
            'name' => 'Special Hours',
            'type' => BusinessHoursExceptionType::SPECIAL_HOURS,
        ]);

        BusinessHoursExceptionTimeRange::create([
            'business_hours_exception_id' => $exception->id,
            'start_time' => '10:00',
            'end_time' => '14:00',
        ]);

        // Reload schedule to get fresh data
        $schedule->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);

        // Monday at 9:30 AM - should be closed (special hours start at 10)
        $testTime1 = Carbon::create(2025, 1, 6, 9, 30, 0);
        $this->assertFalse($schedule->isCurrentlyOpen($testTime1));

        // Monday at 11:00 AM - should be open (within special hours)
        $testTime2 = Carbon::create(2025, 1, 6, 11, 0, 0);
        $this->assertTrue($schedule->isCurrentlyOpen($testTime2));

        // Monday at 15:00 PM - should be closed (special hours end at 14:00)
        $testTime3 = Carbon::create(2025, 1, 6, 15, 0, 0);
        $this->assertFalse($schedule->isCurrentlyOpen($testTime3));
    }

    /**
     * Test that inactive schedules are always considered closed.
     */
    public function test_inactive_schedule_always_returns_closed_status(): void
    {
        $schedule = $this->createScheduleWithStandardHours();
        $schedule->update(['status' => BusinessHoursStatus::INACTIVE]);

        // Monday at 10:00 AM - should be closed because schedule is inactive
        $testTime = Carbon::create(2025, 1, 6, 10, 0, 0);

        $this->assertEquals('closed', $schedule->current_status);
    }

    /**
     * Test that getCurrentRouting returns correct action based on time.
     */
    public function test_get_current_routing_returns_correct_action(): void
    {
        $schedule = $this->createScheduleWithStandardHours();

        // During open hours - should return open_hours_action
        $openTime = Carbon::create(2025, 1, 6, 10, 0, 0); // Monday 10 AM
        $this->assertEquals('ext-101', $schedule->getCurrentRouting($openTime));

        // During closed hours - should return closed_hours_action
        $closedTime = Carbon::create(2025, 1, 6, 18, 0, 0); // Monday 6 PM
        $this->assertEquals('ext-voicemail', $schedule->getCurrentRouting($closedTime));
    }

    /**
     * Test boundary conditions - start and end times.
     */
    public function test_boundary_conditions_for_time_ranges(): void
    {
        $schedule = $this->createScheduleWithStandardHours();

        // Exactly at start time (9:00) - should be open
        $startTime = Carbon::create(2025, 1, 6, 9, 0, 0);
        $this->assertTrue($schedule->isCurrentlyOpen($startTime));

        // One minute before start time (8:59) - should be closed
        $beforeStart = Carbon::create(2025, 1, 6, 8, 59, 0);
        $this->assertFalse($schedule->isCurrentlyOpen($beforeStart));

        // Exactly at end time (17:00) - should be closed (< comparison)
        $endTime = Carbon::create(2025, 1, 6, 17, 0, 0);
        $this->assertFalse($schedule->isCurrentlyOpen($endTime));

        // One minute before end time (16:59) - should be open
        $beforeEnd = Carbon::create(2025, 1, 6, 16, 59, 0);
        $this->assertTrue($schedule->isCurrentlyOpen($beforeEnd));
    }

    /**
     * Test multiple time ranges in a single day.
     */
    public function test_multiple_time_ranges_in_single_day(): void
    {
        $schedule = BusinessHoursSchedule::create([
            'organization_id' => $this->organization->id,
            'name' => 'Split Shift Schedule',
            'status' => BusinessHoursStatus::ACTIVE,
            'open_hours_action' => 'ext-101',
            'closed_hours_action' => 'ext-voicemail',
        ]);

        // Monday: 9-12 and 14-17 (lunch break)
        $monday = BusinessHoursScheduleDay::create([
            'business_hours_schedule_id' => $schedule->id,
            'day_of_week' => DayOfWeek::MONDAY,
            'enabled' => true,
        ]);

        BusinessHoursTimeRange::create([
            'business_hours_schedule_day_id' => $monday->id,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        BusinessHoursTimeRange::create([
            'business_hours_schedule_day_id' => $monday->id,
            'start_time' => '14:00',
            'end_time' => '17:00',
        ]);

        $schedule->load(['scheduleDays.timeRanges']);

        // Monday at 10:00 - open (morning shift)
        $this->assertTrue($schedule->isCurrentlyOpen(Carbon::create(2025, 1, 6, 10, 0, 0)));

        // Monday at 13:00 - closed (lunch break)
        $this->assertFalse($schedule->isCurrentlyOpen(Carbon::create(2025, 1, 6, 13, 0, 0)));

        // Monday at 15:00 - open (afternoon shift)
        $this->assertTrue($schedule->isCurrentlyOpen(Carbon::create(2025, 1, 6, 15, 0, 0)));
    }

    /**
     * Helper method to create a schedule with standard weekday hours (9-5).
     *
     * @return BusinessHoursSchedule
     */
    private function createScheduleWithStandardHours(): BusinessHoursSchedule
    {
        $schedule = BusinessHoursSchedule::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Schedule',
            'status' => BusinessHoursStatus::ACTIVE,
            'open_hours_action' => 'ext-101',
            'closed_hours_action' => 'ext-voicemail',
        ]);

        // Create weekday schedule (Mon-Fri 9-17)
        $weekdays = [
            DayOfWeek::MONDAY,
            DayOfWeek::TUESDAY,
            DayOfWeek::WEDNESDAY,
            DayOfWeek::THURSDAY,
            DayOfWeek::FRIDAY,
        ];

        foreach ($weekdays as $day) {
            $scheduleDay = BusinessHoursScheduleDay::create([
                'business_hours_schedule_id' => $schedule->id,
                'day_of_week' => $day,
                'enabled' => true,
            ]);

            BusinessHoursTimeRange::create([
                'business_hours_schedule_day_id' => $scheduleDay->id,
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]);
        }

        // Create disabled weekend days
        foreach ([DayOfWeek::SATURDAY, DayOfWeek::SUNDAY] as $day) {
            BusinessHoursScheduleDay::create([
                'business_hours_schedule_id' => $schedule->id,
                'day_of_week' => $day,
                'enabled' => false,
            ]);
        }

        // Reload to ensure relationships are loaded
        $schedule->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);

        return $schedule;
    }
}
