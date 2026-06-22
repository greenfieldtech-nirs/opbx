<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNotificationSettings;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Call Tracking Notification Settings API endpoints test suite.
 */
class CallTrackingNotificationSettingsControllerTest extends TestCase
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

    public function test_owner_can_view_notification_settings(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $settings = $this->createSettings($campaign);

        $response = $this->getJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings'
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $settings->id)
            ->assertJsonPath('data.webhook_url', $settings->webhook_url)
            ->assertJsonPath('data.auth_method', $settings->auth_method)
            ->assertJsonPath('data.is_active', $settings->is_active);
    }

    public function test_admin_can_view_notification_settings(): void
    {
        Sanctum::actingAs($this->admin);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->createSettings($campaign);

        $response = $this->getJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings'
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.auth_method', 'bearer_token');
    }

    public function test_agent_cannot_view_notification_settings(): void
    {
        Sanctum::actingAs($this->agent);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->createSettings($campaign);

        $response = $this->getJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings'
        );

        $response->assertStatus(403);
    }

    public function test_reporter_cannot_view_notification_settings(): void
    {
        Sanctum::actingAs($this->reporter);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->createSettings($campaign);

        $response = $this->getJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings'
        );

        $response->assertStatus(403);
    }

    public function test_show_returns_404_when_settings_missing(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->getJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings'
        );

        $response->assertStatus(404);
    }

    public function test_agent_gets_403_when_settings_missing(): void
    {
        Sanctum::actingAs($this->agent);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->getJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings'
        );

        $response->assertStatus(403);
    }

    public function test_owner_can_update_notification_settings(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $settings = $this->createSettings($campaign);

        $payload = [
            'webhook_url' => 'https://example.com/webhooks/updated',
            'auth_method' => 'basic_auth',
            'auth_username' => 'user',
            'auth_secret' => 'pass',
            'enabled_events' => ['call.received', 'call.answered', 'call.missed'],
            'is_active' => false,
        ];

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings',
            $payload
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.webhook_url', 'https://example.com/webhooks/updated')
            ->assertJsonPath('data.auth_method', 'basic_auth')
            ->assertJsonPath('data.auth_username', 'user')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.enabled_events', ['call.received', 'call.answered', 'call.missed']);

        $settings->refresh();
        $this->assertSame('https://example.com/webhooks/updated', $settings->webhook_url);
        $this->assertSame('basic_auth', $settings->auth_method);
        $this->assertFalse($settings->is_active);
    }

    public function test_admin_can_update_notification_settings(): void
    {
        Sanctum::actingAs($this->admin);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $settings = $this->createSettings($campaign);

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings',
            [
                'webhook_url' => 'https://example.com/webhooks/admin',
                'auth_method' => 'none',
                'enabled_events' => ['call.converted'],
                'is_active' => true,
            ]
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.webhook_url', 'https://example.com/webhooks/admin')
            ->assertJsonPath('data.auth_method', 'none');

        $settings->refresh();
        $this->assertSame('https://example.com/webhooks/admin', $settings->webhook_url);
    }

    public function test_agent_cannot_update_notification_settings(): void
    {
        Sanctum::actingAs($this->agent);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->createSettings($campaign);

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings',
            [
                'webhook_url' => 'https://example.com/webhooks/hacked',
                'auth_method' => 'none',
                'enabled_events' => ['call.received'],
            ]
        );

        $response->assertStatus(403);
    }

    public function test_update_creates_settings_when_missing(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $payload = [
            'webhook_url' => 'https://example.com/webhooks/new',
            'auth_method' => 'bearer_token',
            'auth_secret' => 'secret-token',
            'enabled_events' => ['call.received', 'call.converted'],
            'is_active' => true,
        ];

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings',
            $payload
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.webhook_url', 'https://example.com/webhooks/new')
            ->assertJsonPath('data.auth_method', 'bearer_token')
            ->assertJsonPath('data.auth_username', null)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('call_tracking_notification_settings', [
            'call_tracking_campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'webhook_url' => 'https://example.com/webhooks/new',
            'auth_method' => 'bearer_token',
        ]);

        $settings = CallTrackingNotificationSettings::forCampaign($campaign->id)->first();
        $this->assertNotNull($settings);
        $this->assertSame('secret-token', $settings->auth_secret);
    }

    public function test_update_validates_required_fields(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings',
            []
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'webhook_url',
                'auth_method',
                'enabled_events',
            ]);
    }

    public function test_update_validates_bearer_token_requires_secret(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings',
            [
                'webhook_url' => 'https://example.com/webhooks/test',
                'auth_method' => 'bearer_token',
                'enabled_events' => ['call.received'],
            ]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['auth_secret']);
    }

    public function test_update_validates_basic_auth_requires_username_and_secret(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings',
            [
                'webhook_url' => 'https://example.com/webhooks/test',
                'auth_method' => 'basic_auth',
                'auth_secret' => 'pass',
                'enabled_events' => ['call.received'],
            ]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['auth_username']);
    }

    public function test_update_validates_enabled_events(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings',
            [
                'webhook_url' => 'https://example.com/webhooks/test',
                'auth_method' => 'none',
                'enabled_events' => ['call.received', 'invalid.event'],
            ]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['enabled_events.1']);
    }

    public function test_response_does_not_expose_auth_secret(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->createSettings($campaign, ['auth_secret' => 'super-secret']);

        $response = $this->getJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings'
        );

        $response->assertStatus(200)
            ->assertJsonMissingPath('data.auth_secret');
    }

    public function test_show_enforces_tenant_isolation(): void
    {
        Sanctum::actingAs($this->otherOwner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->createSettings($campaign);

        $response = $this->getJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings'
        );

        $response->assertStatus(404);
    }

    public function test_update_enforces_tenant_isolation(): void
    {
        Sanctum::actingAs($this->otherOwner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/notification-settings',
            [
                'webhook_url' => 'https://example.com/webhooks/hacked',
                'auth_method' => 'none',
                'enabled_events' => ['call.received'],
            ]
        );

        $response->assertStatus(404);
    }

    /**
     * Helper to create notification settings for a campaign.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createSettings(CallTrackingCampaign $campaign, array $overrides = []): CallTrackingNotificationSettings
    {
        return CallTrackingNotificationSettings::create(array_merge([
            'organization_id' => $campaign->organization_id,
            'call_tracking_campaign_id' => $campaign->id,
            'webhook_url' => 'https://example.com/webhooks/call-tracking',
            'auth_method' => 'bearer_token',
            'auth_secret' => 'secret',
            'auth_username' => null,
            'enabled_events' => ['call.received', 'call.converted'],
            'is_active' => true,
        ], $overrides));
    }
}
