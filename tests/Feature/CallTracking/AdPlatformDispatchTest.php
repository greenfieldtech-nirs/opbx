<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use App\Jobs\CallTracking\SendMetaConversionEventJob;
use App\Jobs\CallTracking\UploadGoogleAdsConversionJob;
use App\Models\CallTrackingAdPlatformIntegration;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CallTrackingSession;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use App\Services\CallTracking\CallTrackingAdPlatformDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdPlatformDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_converted_session_dispatches_upload_jobs_when_toggles_enabled(): void
    {
        Queue::fake();

        $organization = Organization::factory()->create();
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'google_ads_upload_enabled' => true,
            'meta_upload_enabled' => true,
        ]);
        CallTrackingAdPlatformIntegration::factory()->create([
            'organization_id' => $organization->id,
            'google_ads_enabled' => true,
            'meta_enabled' => true,
        ]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();
        $session = OrganizationScope::bypass(
            fn () => CallTrackingSession::factory()->forCampaignAndNumber($campaign, $number)->create([
                'is_converted' => true,
            ])
        );

        $dispatcher = app(CallTrackingAdPlatformDispatcher::class);
        $dispatcher->dispatch($session);

        Queue::assertPushed(UploadGoogleAdsConversionJob::class);
        Queue::assertPushed(SendMetaConversionEventJob::class);
    }

    public function test_non_converted_session_does_not_dispatch_jobs(): void
    {
        Queue::fake();

        $organization = Organization::factory()->create();
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'google_ads_upload_enabled' => true,
            'meta_upload_enabled' => true,
        ]);
        CallTrackingAdPlatformIntegration::factory()->create([
            'organization_id' => $organization->id,
            'google_ads_enabled' => true,
            'meta_enabled' => true,
        ]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();
        $session = OrganizationScope::bypass(
            fn () => CallTrackingSession::factory()->forCampaignAndNumber($campaign, $number)->create([
                'is_converted' => false,
            ])
        );

        $dispatcher = app(CallTrackingAdPlatformDispatcher::class);
        $dispatcher->dispatch($session);

        Queue::assertNothingPushed();
    }
}
