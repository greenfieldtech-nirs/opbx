<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\CampaignStatus;
use App\Models\AutoDialerCampaign;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoDialerCampaignIsRunnableTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
    }

    private function createCampaign(array $overrides = []): AutoDialerCampaign
    {
        return AutoDialerCampaign::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
            'start_date' => '2020-01-01',
            'end_date' => '2030-12-31',
            'schedule' => [
                'monday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                'tuesday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                'wednesday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                'thursday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                'friday' => ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]],
            ],
        ], $overrides));
    }

    public function test_is_runnable_uses_campaign_timezone_and_returns_true_within_local_window(): void
    {
        // A New York campaign whose window is 09:00-17:00 local.
        $campaign = $this->createCampaign(['timezone' => 'America/New_York']);

        // 14:00 in New York (a weekday). In UTC this is 18:00/19:00 depending on
        // DST — i.e. OUTSIDE the naive UTC comparison, but INSIDE the local one.
        $this->travelTo(now('America/New_York')->next('wednesday')->setTime(14, 0));

        $this->assertTrue($campaign->isRunnable());
    }

    public function test_is_runnable_returns_false_when_utc_matches_but_local_time_is_outside_window(): void
    {
        $campaign = $this->createCampaign(['timezone' => 'America/New_York']);

        // 14:00 UTC on a weekday. In New York this is 09:00/10:00 — still inside,
        // so pick a time that is clearly outside locally: 22:00 UTC == 17:00/18:00 NY.
        $this->travelTo(now('UTC')->next('wednesday')->setTime(23, 0));

        // 23:00 UTC == 18:00/19:00 New York => outside the 09:00-17:00 window.
        $this->assertFalse($campaign->isRunnable());
    }

    public function test_is_runnable_utc_campaign_still_works(): void
    {
        $campaign = $this->createCampaign(['timezone' => 'UTC']);

        $this->travelTo(now('UTC')->next('wednesday')->setTime(14, 0));

        $this->assertTrue($campaign->isRunnable());
    }

    public function test_is_runnable_false_when_status_not_active(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::PAUSED,
            'timezone' => 'UTC',
        ]);

        $this->travelTo(now('UTC')->next('wednesday')->setTime(14, 0));

        $this->assertFalse($campaign->isRunnable());
    }

    public function test_is_runnable_false_on_disabled_day(): void
    {
        $campaign = $this->createCampaign(['timezone' => 'UTC']);

        // Saturday is not in the schedule.
        $this->travelTo(now('UTC')->next('saturday')->setTime(14, 0));

        $this->assertFalse($campaign->isRunnable());
    }
}
