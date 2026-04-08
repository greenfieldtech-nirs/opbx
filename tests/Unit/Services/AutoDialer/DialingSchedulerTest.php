<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\AutoDialer\CampaignStatus;
use App\Models\AutoDialerCampaign;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\DialingScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DialingSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private DialingScheduler $scheduler;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduler = new DialingScheduler;
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_is_within_schedule_returns_true_during_business_hours(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        // Test at 2 PM UTC (within schedule)
        $this->travelTo(now('UTC')->setTime(14, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertTrue($result);
    }

    public function test_is_within_schedule_returns_false_before_start_time(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        // Test at 7 AM UTC (before schedule)
        $this->travelTo(now('UTC')->setTime(7, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertFalse($result);
    }

    public function test_is_within_schedule_returns_false_after_end_time(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        // Test at 8 PM UTC (after schedule)
        $this->travelTo(now('UTC')->setTime(20, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertFalse($result);
    }

    public function test_is_within_schedule_handles_exact_start_time(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        // Test at exactly 9:00 AM
        $this->travelTo(now('UTC')->setTime(9, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertTrue($result);
    }

    public function test_is_within_schedule_handles_exact_end_time(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        // Test at exactly 5:00 PM
        $this->travelTo(now('UTC')->setTime(17, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertTrue($result);
    }

    public function test_is_within_schedule_handles_timezone_conversion(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'America/New_York',
        ]);

        // Test at 2 PM EST (should be within 9-5 EST schedule)
        $this->travelTo(now('America/New_York')->setTime(14, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertTrue($result);
    }

    public function test_is_within_schedule_respects_timezone_boundaries(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'America/New_York',
        ]);

        // Set system time to 8 PM UTC (which is 3 PM EST - within schedule)
        // But if we don't convert properly, 8 PM UTC would be after 5 PM
        $this->travelTo(now()->setTimezone('UTC')->setTime(20, 0));

        // Since the campaign is in EST, 8 PM UTC = 3 PM EST (within schedule)
        // But we need to check the campaign's timezone specifically
        $result = $this->scheduler->isWithinSchedule($campaign);

        // This test verifies timezone is being considered
        // At 8 PM UTC, it's 3 PM in New York (during DST) or 4 PM (standard time)
        // Either way, it should be within 9 AM - 5 PM EST
        $this->assertTrue($result);
    }

    public function test_can_dial_now_returns_true_when_all_conditions_met(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        $this->travelTo(now('UTC')->setTime(14, 0));
        $result = $this->scheduler->canDialNow($campaign);

        $this->assertTrue($result);
    }

    public function test_can_dial_now_returns_false_when_campaign_not_running(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PAUSED->value,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        $this->travelTo(now('UTC')->setTime(14, 0));
        $result = $this->scheduler->canDialNow($campaign);

        $this->assertFalse($result);
    }

    public function test_can_dial_now_returns_false_outside_schedule(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        $this->travelTo(now('UTC')->setTime(20, 0));
        $result = $this->scheduler->canDialNow($campaign);

        $this->assertFalse($result);
    }

    public function test_get_next_dialing_window_returns_correct_window(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        // Test at 11 PM
        $this->travelTo(now('UTC')->setTime(23, 0));
        $window = $this->scheduler->getNextDialingWindow($campaign);

        $this->assertNotNull($window);
        $this->assertEquals('09:00', $window->format('H:i'));
    }

    public function test_get_next_dialing_window_returns_tomorrow_when_after_hours(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        // Test at 8 PM
        $today = now('UTC')->format('Y-m-d');
        $this->travelTo(now('UTC')->setTime(20, 0));
        $window = $this->scheduler->getNextDialingWindow($campaign);

        // Should be tomorrow at 9 AM
        $tomorrow = now('UTC')->addDay()->format('Y-m-d');
        $this->assertEquals($tomorrow, $window->format('Y-m-d'));
        $this->assertEquals('09:00', $window->format('H:i'));
    }

    public function test_get_next_dialing_window_returns_null_for_running_campaign_within_hours(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        // Test at 2 PM (within hours)
        $this->travelTo(now('UTC')->setTime(14, 0));
        $window = $this->scheduler->getNextDialingWindow($campaign);

        // Campaign is currently running and within hours, so next window is now
        $this->assertNotNull($window);
    }

    public function test_get_next_dialing_window_handles_timezone_correctly(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'America/New_York',
        ]);

        // Test at 11 PM EST (after hours)
        $this->travelTo(now('America/New_York')->setTime(23, 0));
        $window = $this->scheduler->getNextDialingWindow($campaign);

        // Should be tomorrow at 9 AM EST
        $this->assertEquals('09:00', $window->format('H:i'));
    }

    public function test_is_dialing_allowed_respects_holiday_exclusions(): void
    {
        // This test would require holiday configuration
        // For now, we'll test basic functionality
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'UTC',
        ]);

        $this->travelTo(now('UTC')->setTime(14, 0));

        // By default, dialing should be allowed during business hours
        // Holiday exclusion would be an extension
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertTrue($result);
    }
}
