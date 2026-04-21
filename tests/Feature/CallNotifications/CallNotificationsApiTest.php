<?php

declare(strict_types=1);

namespace Tests\Feature\CallNotifications;

use App\Models\CallNotificationLog;
use App\Models\CallNotificationsSettings;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CallNotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);
    }

    public function test_show_returns_null_when_not_configured(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/call-notifications/settings');

        $response->assertOk()
            ->assertJson([
                'data' => null,
                'message' => 'Notification settings not configured',
            ]);
    }

    public function test_show_returns_settings_when_configured(): void
    {
        $settings = CallNotificationsSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_url' => 'https://example.com/webhook',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/call-notifications/settings');

        $response->assertOk()
            ->assertJsonPath('data.webhook_url', 'https://example.com/webhook')
            ->assertJsonPath('data.id', $settings->id);
    }

    public function test_store_creates_new_settings(): void
    {
        $payload = [
            'webhook_url' => 'https://example.com/webhook',
            'auth_method' => 'hmac',
            'hmac_secret' => 'secret-key',
            'retry_attempts' => 3,
            'retry_backoff_seconds' => 60,
            'rate_limit_per_minute' => 500,
            'enabled_events' => ['new', 'ringing', 'connected', 'completed'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/call-notifications/settings', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.webhook_url', 'https://example.com/webhook')
            ->assertJsonPath('data.auth_method', 'hmac')
            ->assertJsonPath('data.enabled_events', ['new', 'ringing', 'connected', 'completed']);

        $this->assertDatabaseHas('call_notifications_settings', [
            'organization_id' => $this->organization->id,
            'webhook_url' => 'https://example.com/webhook',
        ]);
    }

    public function test_store_returns_409_when_settings_exist(): void
    {
        CallNotificationsSettings::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/call-notifications/settings', [
                'webhook_url' => 'https://example.com/webhook',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'Notification settings already exist. Use PUT to update.');
    }

    public function test_update_modifies_existing_settings(): void
    {
        $settings = CallNotificationsSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_url' => 'https://old.example.com/webhook',
            'rate_limit_per_minute' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/call-notifications/settings', [
                'webhook_url' => 'https://new.example.com/webhook',
                'rate_limit_per_minute' => 200,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.webhook_url', 'https://new.example.com/webhook')
            ->assertJsonPath('data.rate_limit_per_minute', 200);

        $this->assertDatabaseHas('call_notifications_settings', [
            'id' => $settings->id,
            'webhook_url' => 'https://new.example.com/webhook',
        ]);
    }

    public function test_update_returns_404_when_not_found(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/call-notifications/settings', [
                'webhook_url' => 'https://example.com/webhook',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('error', 'Notification settings not found. Use POST to create.');
    }

    public function test_destroy_deletes_settings(): void
    {
        $settings = CallNotificationsSettings::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/call-notifications/settings');

        $response->assertOk()
            ->assertJsonPath('message', 'Notification settings deleted successfully');

        $this->assertDatabaseMissing('call_notifications_settings', [
            'id' => $settings->id,
        ]);
    }

    public function test_destroy_returns_404_when_not_found(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/call-notifications/settings');

        $response->assertStatus(404)
            ->assertJsonPath('error', 'Notification settings not found');
    }

    public function test_test_webhook_sends_notification_and_returns_success(): void
    {
        CallNotificationsSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_url' => 'https://example.com/webhook',
            'enabled_events' => ['new', 'ringing'],
        ]);

        Http::fake([
            'example.com/webhook' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/call-notifications/test');

        $response->assertOk()
            ->assertJsonPath('message', 'Test webhook delivered successfully')
            ->assertJsonPath('webhook_url', 'https://example.com/webhook');

        // Verify a log was created
        $this->assertDatabaseHas('call_notification_logs', [
            'organization_id' => $this->organization->id,
            'is_success' => true,
        ]);
    }

    public function test_test_webhook_returns_error_when_not_configured(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/call-notifications/test');

        $response->assertStatus(400)
            ->assertJsonPath('error', 'Notification settings not configured');
    }

    public function test_logs_returns_latest_status_per_session(): void
    {
        // Create multiple logs for the same session
        $sessionToken = 'test-session-token';

        CallNotificationLog::factory()->create([
            'organization_id' => $this->organization->id,
            'call_session_token' => $sessionToken,
            'status' => 'new',
            'is_success' => true,
            'created_at' => now()->subMinutes(5),
        ]);

        CallNotificationLog::factory()->create([
            'organization_id' => $this->organization->id,
            'call_session_token' => $sessionToken,
            'status' => 'ringing',
            'is_success' => true,
            'created_at' => now()->subMinutes(4),
        ]);

        CallNotificationLog::factory()->create([
            'organization_id' => $this->organization->id,
            'call_session_token' => $sessionToken,
            'status' => 'connected',
            'is_success' => true,
            'created_at' => now()->subMinutes(3),
        ]);

        // Create a different session
        CallNotificationLog::factory()->create([
            'organization_id' => $this->organization->id,
            'call_session_token' => 'other-session',
            'status' => 'completed',
            'is_success' => true,
            'created_at' => now()->subMinutes(2),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/call-notifications/logs');

        $response->assertOk()
            ->assertJsonCount(2, 'data'); // Two unique sessions

        // Should return the latest status for each session
        $statuses = collect($response->json('data'))->pluck('status')->all();
        $this->assertContains('connected', $statuses);
        $this->assertContains('completed', $statuses);
    }

    public function test_logs_show_all_returns_all_attempts(): void
    {
        $sessionToken = 'test-session-token';

        CallNotificationLog::factory()->create([
            'organization_id' => $this->organization->id,
            'call_session_token' => $sessionToken,
            'status' => 'new',
            'is_success' => false,
        ]);

        CallNotificationLog::factory()->create([
            'organization_id' => $this->organization->id,
            'call_session_token' => $sessionToken,
            'status' => 'new',
            'is_success' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/call-notifications/logs?show_all=true');

        $response->assertOk()
            ->assertJsonCount(2, 'data'); // Both attempts shown
    }

    public function test_logs_filters_by_status(): void
    {
        CallNotificationLog::factory()->create([
            'organization_id' => $this->organization->id,
            'call_session_token' => 'session-1',
            'status' => 'completed',
            'is_success' => true,
        ]);

        CallNotificationLog::factory()->create([
            'organization_id' => $this->organization->id,
            'call_session_token' => 'session-2',
            'status' => 'failed',
            'is_success' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/call-notifications/logs?status=failed');

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'failed');
    }

    public function test_session_logs_returns_all_logs_for_session(): void
    {
        $sessionToken = 'specific-session-token';

        CallNotificationLog::factory()->create([
            'organization_id' => $this->organization->id,
            'call_session_token' => $sessionToken,
            'status' => 'new',
            'is_success' => true,
        ]);

        CallNotificationLog::factory()->create([
            'organization_id' => $this->organization->id,
            'call_session_token' => $sessionToken,
            'status' => 'connected',
            'is_success' => true,
        ]);

        // Different session - should not be included
        CallNotificationLog::factory()->create([
            'organization_id' => $this->organization->id,
            'call_session_token' => 'other-session',
            'status' => 'completed',
            'is_success' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/call-notifications/logs/{$sessionToken}");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.call_session_token', $sessionToken)
            ->assertJsonPath('data.1.call_session_token', $sessionToken);
    }

    public function test_rate_limit_returns_status(): void
    {
        $settings = CallNotificationsSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'rate_limit_per_minute' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/call-notifications/rate-limit');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'limit',
                    'current',
                    'remaining',
                    'reset_in_seconds',
                ],
            ])
            ->assertJsonPath('data.limit', 100);
    }

    public function test_unauthorized_access_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/call-notifications/settings');

        $response->assertUnauthorized();
    }
}
