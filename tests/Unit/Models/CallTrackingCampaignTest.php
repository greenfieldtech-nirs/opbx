<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CallTrackingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallTrackingCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_casts_ad_platform_upload_toggles_as_boolean(): void
    {
        $campaign = CallTrackingCampaign::factory()->create([
            'google_ads_upload_enabled' => true,
            'meta_upload_enabled' => false,
        ]);

        $this->assertTrue($campaign->fresh()->google_ads_upload_enabled);
        $this->assertFalse($campaign->fresh()->meta_upload_enabled);
    }
}
