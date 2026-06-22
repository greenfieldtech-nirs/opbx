<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CallTrackingAdPlatformIntegration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CallTrackingAdPlatformIntegration */
class CallTrackingAdPlatformIntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'organization_id' => $this->organization_id,
            'google_ads' => [
                'enabled' => $this->google_ads_enabled,
                'is_configured' => ! empty($this->google_ads_customer_id)
                    && ! empty($this->google_ads_developer_token)
                    && ! empty($this->google_ads_refresh_token)
                    && ! empty($this->google_ads_conversion_action_resource_name),
            ],
            'meta' => [
                'enabled' => $this->meta_enabled,
                'is_configured' => ! empty($this->meta_pixel_id) && ! empty($this->meta_access_token),
            ],
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
