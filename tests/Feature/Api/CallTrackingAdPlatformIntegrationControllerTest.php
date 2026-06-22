<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallTrackingAdPlatformIntegration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CallTrackingAdPlatformIntegrationControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Organization $otherOrganization;
    private User $owner;
    private User $admin;
    private User $agent;
    private User $reporter;
    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_ADMIN,
        ]);
        $this->agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);
        $this->reporter = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::REPORTER,
        ]);
        $this->otherOwner = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'role' => UserRole::OWNER,
        ]);
    }

    public function test_owner_can_view_empty_integration_settings(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/call-tracking-ad-platform-integrations');

        $response->assertStatus(200)
            ->assertJsonPath('data.google_ads.enabled', false)
            ->assertJsonPath('data.meta.enabled', false);
    }

    public function test_admin_can_view_integration_settings(): void
    {
        Sanctum::actingAs($this->admin);

        CallTrackingAdPlatformIntegration::factory()->create([
            'organization_id' => $this->organization->id,
            'google_ads_enabled' => true,
        ]);

        $response = $this->getJson('/api/v1/call-tracking-ad-platform-integrations');

        $response->assertStatus(200)
            ->assertJsonPath('data.google_ads.enabled', true);
    }

    public function test_agent_cannot_view_integration_settings(): void
    {
        Sanctum::actingAs($this->agent);

        $response = $this->getJson('/api/v1/call-tracking-ad-platform-integrations');

        $response->assertStatus(403);
    }

    public function test_reporter_cannot_view_integration_settings(): void
    {
        Sanctum::actingAs($this->reporter);

        $response = $this->getJson('/api/v1/call-tracking-ad-platform-integrations');

        $response->assertStatus(403);
    }

    public function test_owner_can_update_integration_settings(): void
    {
        Sanctum::actingAs($this->owner);

        $payload = [
            'google_ads_enabled' => true,
            'google_ads_developer_token' => 'dev-token',
            'google_ads_refresh_token' => 'refresh-token',
            'google_ads_customer_id' => '123-456-7890',
            'google_ads_conversion_action_resource_name' => 'customers/123/conversionActions/456',
            'meta_enabled' => false,
        ];

        $response = $this->putJson('/api/v1/call-tracking-ad-platform-integrations', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.google_ads.enabled', true)
            ->assertJsonPath('data.google_ads.is_configured', true)
            ->assertJsonMissingPath('data.google_ads.developer_token');

        $this->assertDatabaseHas('call_tracking_ad_platform_integrations', [
            'organization_id' => $this->organization->id,
            'google_ads_enabled' => true,
        ]);
    }

    public function test_update_validates_missing_google_credentials_when_enabled(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->putJson('/api/v1/call-tracking-ad-platform-integrations', [
            'google_ads_enabled' => true,
            'meta_enabled' => false,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['google_ads_developer_token']);
    }

    public function test_agent_cannot_update_integrations(): void
    {
        Sanctum::actingAs($this->agent);

        $response = $this->putJson('/api/v1/call-tracking-ad-platform-integrations', [
            'google_ads_enabled' => false,
            'meta_enabled' => false,
        ]);

        $response->assertStatus(403);
    }

    public function test_update_preserves_existing_secrets_when_not_sent(): void
    {
        Sanctum::actingAs($this->owner);

        CallTrackingAdPlatformIntegration::factory()->create([
            'organization_id' => $this->organization->id,
            'google_ads_enabled' => true,
            'google_ads_developer_token' => 'existing-token',
            'google_ads_refresh_token' => 'existing-refresh',
            'google_ads_customer_id' => '123',
            'google_ads_conversion_action_resource_name' => 'customers/123/actions/1',
            'meta_enabled' => false,
        ]);

        $response = $this->putJson('/api/v1/call-tracking-ad-platform-integrations', [
            'google_ads_enabled' => true,
            'meta_enabled' => false,
        ]);

        $response->assertStatus(200);

        $integration = CallTrackingAdPlatformIntegration::forOrganization($this->organization->id)->first();
        $this->assertSame('existing-token', $integration->google_ads_developer_token);
    }
}
