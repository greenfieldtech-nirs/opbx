<?php

declare(strict_types=1);

namespace App\Services\CallTracking;

use App\Jobs\CallTracking\SendMetaConversionEventJob;
use App\Jobs\CallTracking\UploadGoogleAdsConversionJob;
use App\Models\CallTrackingAdPlatformIntegration;
use App\Models\CallTrackingSession;
use App\Scopes\OrganizationScope;

class CallTrackingAdPlatformDispatcher
{
    public function dispatch(CallTrackingSession $session): void
    {
        if (! $session->is_converted) {
            return;
        }

        $campaign = OrganizationScope::bypass(
            fn () => $session->campaign
        );

        if (! $campaign) {
            return;
        }

        $integration = OrganizationScope::bypass(
            fn () => CallTrackingAdPlatformIntegration::forOrganization($session->organization_id)->first()
        );

        if (! $integration) {
            return;
        }

        if ($campaign->google_ads_upload_enabled && $integration->google_ads_enabled) {
            UploadGoogleAdsConversionJob::dispatch($session->id);
        }

        if ($campaign->meta_upload_enabled && $integration->meta_enabled) {
            SendMetaConversionEventJob::dispatch($session->id);
        }
    }
}
