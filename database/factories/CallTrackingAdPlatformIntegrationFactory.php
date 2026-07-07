<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CallTrackingAdPlatformIntegration;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class CallTrackingAdPlatformIntegrationFactory extends Factory
{
    protected $model = CallTrackingAdPlatformIntegration::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'google_ads_enabled' => false,
            'google_ads_developer_token' => null,
            'google_ads_refresh_token' => null,
            'google_ads_customer_id' => null,
            'google_ads_conversion_action_resource_name' => null,
            'meta_enabled' => false,
            'meta_pixel_id' => null,
            'meta_access_token' => null,
        ];
    }
}
