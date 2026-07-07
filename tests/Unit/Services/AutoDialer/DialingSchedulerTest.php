<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\CampaignStatus;
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

    private function createCampaign(array $overrides = []): AutoDialerCampaign
    {
        return AutoDialerCampaign::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'start_time' => 9,
            'end_time' => 17,
            'timezone' => 'UTC',
            'start_date' => '2020-01-01',
            'end_date' => '2030-12-31',
            'days_active' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
        ], $overrides));
    }

    public function test_is_within_schedule_returns_true_during_business_hours(): void
    {
        $campaign = $this->createCampaign();

        $this->travelTo(now('UTC')->setTime(14, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertTrue($result);
    }

    public function test_is_within_schedule_returns_false_before_start_time(): void
    {
        $campaign = $this->createCampaign();

        $this->travelTo(now('UTC')->setTime(7, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertFalse($result);
    }

    public function test_is_within_schedule_returns_false_after_end_time(): void
    {
        $campaign = $this->createCampaign();

        $this->travelTo(now('UTC')->setTime(20, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertFalse($result);
    }

    public function test_is_within_schedule_handles_exact_start_time(): void
    {
        $campaign = $this->createCampaign();

        $this->travelTo(now('UTC')->setTime(9, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertTrue($result);
    }

    public function test_is_within_schedule_handles_exact_end_time(): void
    {
        $campaign = $this->createCampaign();

        $this->travelTo(now('UTC')->setTime(17, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        // Production uses currentHour < end_time, so exactly end time is outside.
        $this->assertFalse($result);
    }

    public function test_is_within_schedule_handles_timezone_conversion(): void
    {
        // Freeze time before creating the campaign so the factory's date range
        // aligns with the time we assert against.
        $this->travelTo(now('UTC')->setDate(2026, 7, 3)->setTime(14, 0));

        $campaign = $this->createCampaign([
            'timezone' => 'America/New_York',
        ]);

        $this->travelTo(now('America/New_York')->setTime(14, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertTrue($result);
    }

    public function test_is_within_schedule_respects_timezone_boundaries(): void
    {
        $campaign = $this->createCampaign([
            'timezone' => 'America/New_York',
        ]);

        // 8 PM UTC is 3-4 PM in New York (within 9-5 schedule).
        $this->travelTo(now()->setTimezone('UTC')->setTime(20, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertTrue($result);
    }

    public function test_is_within_schedule_returns_true_when_active(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
        ]);

        $this->travelTo(now('UTC')->setTime(14, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertTrue($result);
    }

    public function test_is_within_schedule_returns_false_outside_schedule(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
        ]);

        $this->travelTo(now('UTC')->setTime(20, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertFalse($result);
    }

    public function test_get_next_scheduled_time_returns_next_window_today(): void
    {
        $campaign = $this->createCampaign();

        // Before business hours today
        $this->travelTo(now('UTC')->setTime(7, 0));
        $window = $this->scheduler->getNextScheduledTime($campaign);

        $this->assertNotNull($window);
        $this->assertEquals('09:00', $window->format('H:i'));
    }

    public function test_get_next_scheduled_time_returns_tomorrow_when_after_hours(): void
    {
        // Use a Wednesday so the next calendar day (Thursday) is in the default
        // Mon-Fri active days and the assertion is deterministic.
        $this->travelTo(now('UTC')->setDate(2026, 7, 1)->setTime(20, 0));

        $campaign = $this->createCampaign();

        $today = now('UTC')->format('Y-m-d');
        $window = $this->scheduler->getNextScheduledTime($campaign);

        $tomorrow = now('UTC')->addDay()->format('Y-m-d');
        $this->assertEquals($tomorrow, $window->format('Y-m-d'));
        $this->assertEquals('09:00', $window->format('H:i'));
    }

    public function test_get_next_scheduled_time_returns_now_for_running_campaign_within_hours(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
        ]);

        $this->travelTo(now('UTC')->setTime(14, 0));
        $window = $this->scheduler->getNextScheduledTime($campaign);

        $this->assertNotNull($window);
        $this->assertEquals('14:00', $window->format('H:i'));
    }

    public function test_get_next_scheduled_time_handles_timezone_correctly(): void
    {
        $campaign = $this->createCampaign([
            'timezone' => 'America/New_York',
        ]);

        $this->travelTo(now('America/New_York')->setTime(23, 0));
        $window = $this->scheduler->getNextScheduledTime($campaign);

        $this->assertNotNull($window);
        $this->assertEquals('09:00', $window->format('H:i'));
    }

    public function test_is_dialing_allowed_during_business_hours(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
        ]);

        $this->travelTo(now('UTC')->setTime(14, 0));
        $result = $this->scheduler->isWithinSchedule($campaign);

        $this->assertTrue($result);
    }
}
