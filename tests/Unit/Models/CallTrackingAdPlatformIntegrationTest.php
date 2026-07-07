<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CallTrackingAdPlatformIntegration;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallTrackingAdPlatformIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_organization(): void
    {
        $organization = Organization::factory()->create();
        $integration = CallTrackingAdPlatformIntegration::factory()->create([
            'organization_id' => $organization->id,
        ]);

        $this->assertInstanceOf(Organization::class, $integration->fresh()->organization);
        $this->assertSame($organization->id, $integration->organization->id);
    }

    public function test_boolean_fields_are_cast(): void
    {
        $integration = CallTrackingAdPlatformIntegration::factory()->create([
            'google_ads_enabled' => true,
            'meta_enabled' => true,
        ]);

        $this->assertTrue($integration->fresh()->google_ads_enabled);
        $this->assertTrue($integration->fresh()->meta_enabled);
    }

    public function test_credential_fields_are_encrypted(): void
    {
        $integration = CallTrackingAdPlatformIntegration::factory()->create([
            'google_ads_developer_token' => 'dev-token',
            'google_ads_refresh_token' => 'refresh-token',
            'google_ads_customer_id' => '123',
            'google_ads_conversion_action_resource_name' => 'customers/123/actions/1',
            'meta_pixel_id' => 'pixel-123',
            'meta_access_token' => 'access-token',
        ]);

        $this->assertSame('dev-token', $integration->fresh()->google_ads_developer_token);
        $this->assertSame('refresh-token', $integration->fresh()->google_ads_refresh_token);
        $this->assertSame('123', $integration->fresh()->google_ads_customer_id);
        $this->assertSame('customers/123/actions/1', $integration->fresh()->google_ads_conversion_action_resource_name);
        $this->assertSame('pixel-123', $integration->fresh()->meta_pixel_id);
        $this->assertSame('access-token', $integration->fresh()->meta_access_token);
    }

    public function test_scope_for_organization_filters_by_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        CallTrackingAdPlatformIntegration::factory()->create([
            'organization_id' => $organization->id,
        ]);

        CallTrackingAdPlatformIntegration::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $results = OrganizationScope::bypass(
            fn () => CallTrackingAdPlatformIntegration::forOrganization($organization->id)->get()
        );

        $this->assertCount(1, $results);
        $this->assertSame($organization->id, $results->first()->organization_id);
    }
}
