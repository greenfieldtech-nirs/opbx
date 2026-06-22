<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\CallTrackingAdPlatformIntegrationResource;
use App\Models\CallTrackingAdPlatformIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallTrackingAdPlatformIntegrationResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_omits_secrets(): void
    {
        $integration = CallTrackingAdPlatformIntegration::factory()->create([
            'google_ads_developer_token' => 'secret',
            'google_ads_refresh_token' => 'secret',
            'google_ads_customer_id' => '123',
            'google_ads_conversion_action_resource_name' => 'customers/123/actions/1',
            'meta_pixel_id' => 'pixel',
            'meta_access_token' => 'secret',
        ]);

        $resource = (new CallTrackingAdPlatformIntegrationResource($integration))->toArray(request());

        $this->assertArrayNotHasKey('google_ads_developer_token', $resource);
        $this->assertArrayNotHasKey('google_ads_refresh_token', $resource);
        $this->assertArrayNotHasKey('google_ads_customer_id', $resource);
        $this->assertArrayNotHasKey('google_ads_conversion_action_resource_name', $resource);
        $this->assertArrayNotHasKey('meta_pixel_id', $resource);
        $this->assertArrayNotHasKey('meta_access_token', $resource);
    }

    public function test_google_ads_is_configured_requires_all_fields(): void
    {
        $integration = CallTrackingAdPlatformIntegration::factory()->create([
            'google_ads_enabled' => true,
            'google_ads_developer_token' => 'token',
            'google_ads_refresh_token' => 'refresh',
            'google_ads_customer_id' => '123',
            'google_ads_conversion_action_resource_name' => 'customers/123/actions/1',
        ]);

        $resource = (new CallTrackingAdPlatformIntegrationResource($integration))->toArray(request());

        $this->assertTrue($resource['google_ads']['enabled']);
        $this->assertTrue($resource['google_ads']['is_configured']);
    }

    public function test_google_ads_is_not_configured_when_conversion_action_missing(): void
    {
        $integration = CallTrackingAdPlatformIntegration::factory()->create([
            'google_ads_enabled' => true,
            'google_ads_developer_token' => 'token',
            'google_ads_customer_id' => '123',
            'google_ads_conversion_action_resource_name' => null,
        ]);

        $resource = (new CallTrackingAdPlatformIntegrationResource($integration))->toArray(request());

        $this->assertFalse($resource['google_ads']['is_configured']);
    }

    public function test_google_ads_is_not_configured_when_refresh_token_missing(): void
    {
        $integration = CallTrackingAdPlatformIntegration::factory()->create([
            'google_ads_enabled' => true,
            'google_ads_developer_token' => 'token',
            'google_ads_customer_id' => '123',
            'google_ads_conversion_action_resource_name' => 'customers/123/actions/1',
        ]);

        $resource = (new CallTrackingAdPlatformIntegrationResource($integration))->toArray(request());

        $this->assertFalse($resource['google_ads']['is_configured']);
    }

    public function test_meta_is_configured_requires_pixel_and_token(): void
    {
        $integration = CallTrackingAdPlatformIntegration::factory()->create([
            'meta_enabled' => true,
            'meta_pixel_id' => 'pixel-123',
            'meta_access_token' => 'token',
        ]);

        $resource = (new CallTrackingAdPlatformIntegrationResource($integration))->toArray(request());

        $this->assertTrue($resource['meta']['enabled']);
        $this->assertTrue($resource['meta']['is_configured']);
    }
}
