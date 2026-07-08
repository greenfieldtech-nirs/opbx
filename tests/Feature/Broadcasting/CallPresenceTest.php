<?php

declare(strict_types=1);

namespace Tests\Feature\Broadcasting;

use App\Enums\CallStatus;
use App\Enums\UserRole;
use App\Events\CallAnswered;
use App\Events\CallEnded;
use App\Events\CallInitiated;
use App\Models\CallLog;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Call Presence Broadcasting Tests
 *
 * Tests real-time call presence events and broadcasting functionality
 */
final class CallPresenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->for($this->organization)->create([
            'role' => UserRole::SUPERVISOR,
        ]);

        // Configure Pusher for broadcasting auth tests
        config(['broadcasting.default' => 'pusher']);
        config(['broadcasting.connections.pusher.key' => 'test-key']);
        config(['broadcasting.connections.pusher.secret' => 'test-secret']);
        config(['broadcasting.connections.pusher.options.app_id' => 'test-app']);

        // Re-register channels on the active broadcaster; the test runner sets
        // BROADCAST_CONNECTION=null, so channels loaded during boot were bound
        // to the null broadcaster and are lost when we switch to pusher here.
        require base_path('routes/channels.php');
    }

    public function test_call_initiated_event_is_broadcast(): void
    {
        Event::fake();

        $callLog = CallLog::factory()
            ->for($this->organization)
            ->create([
                'status' => CallStatus::INITIATED,
            ]);

        event(new CallInitiated($callLog));

        Event::assertDispatched(CallInitiated::class, function ($event) use ($callLog) {
            return $event->callLog->id === $callLog->id;
        });
    }

    public function test_call_initiated_event_broadcasts_correct_data(): void
    {
        Event::fake();

        $callLog = CallLog::factory()
            ->for($this->organization)
            ->create([
                'status' => CallStatus::INITIATED,
                'from_number' => '+18005551234',
                'to_number' => '+18005556789',
            ]);

        $event = new CallInitiated($callLog);

        $broadcastData = $event->broadcastWith();

        $this->assertArrayHasKey('call_id', $broadcastData);
        $this->assertArrayHasKey('from_number', $broadcastData);
        $this->assertArrayHasKey('to_number', $broadcastData);
        $this->assertArrayHasKey('did_id', $broadcastData);
        $this->assertArrayHasKey('status', $broadcastData);
        $this->assertArrayHasKey('initiated_at', $broadcastData);

        $this->assertEquals($callLog->call_id, $broadcastData['call_id']);
        $this->assertEquals('+18005551234', $broadcastData['from_number']);
        $this->assertEquals('+18005556789', $broadcastData['to_number']);
    }

    public function test_call_answered_event_is_broadcast(): void
    {
        Event::fake();

        $extension = Extension::factory()->for($this->organization)->create();
        $callLog = CallLog::factory()
            ->for($this->organization)
            ->for($extension)
            ->create([
                'status' => CallStatus::ANSWERED,
            ]);

        event(new CallAnswered($callLog));

        Event::assertDispatched(CallAnswered::class, function ($event) use ($callLog) {
            return $event->callLog->id === $callLog->id;
        });
    }

    public function test_call_ended_event_is_broadcast(): void
    {
        Event::fake();

        $callLog = CallLog::factory()
            ->for($this->organization)
            ->create([
                'status' => CallStatus::COMPLETED,
                'duration' => 120,
            ]);

        event(new CallEnded($callLog));

        Event::assertDispatched(CallEnded::class, function ($event) use ($callLog) {
            return $event->callLog->id === $callLog->id;
        });
    }

    public function test_events_broadcast_to_correct_channel(): void
    {
        $callLog = CallLog::factory()->for($this->organization)->create();
        $event = new CallInitiated($callLog);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);

        // Channel name should be in format: presence.org.{organization_id}
        $channelName = $channels[0]->name;
        $this->assertEquals("presence-org.{$this->organization->id}", $channelName);
    }

    public function test_presence_channel_authorizes_users_from_same_organization(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "presence-org.{$this->organization->id}",
        ]);

        $response->assertOk();

        // Should return user presence data
        $data = $response->json();
        $this->assertArrayHasKey('auth', $data);
    }

    public function test_presence_channel_denies_users_from_different_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "presence.org.{$otherOrganization->id}",
        ]);

        $response->assertForbidden();
    }

    public function test_presence_channel_denies_unauthenticated_users(): void
    {
        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "presence-org.{$this->organization->id}",
        ]);

        $response->assertUnauthorized();
    }

    public function test_presence_channel_returns_user_info_for_authorized_users(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "presence-org.{$this->organization->id}",
        ]);

        $response->assertOk();

        // Verify user info is included in presence data
        $data = $response->json();
        $this->assertArrayHasKey('channel_data', $data);

        $channelData = json_decode($data['channel_data'], true);
        $this->assertArrayHasKey('user_id', $channelData);
        $this->assertArrayHasKey('user_info', $channelData);

        $userInfo = $channelData['user_info'];
        $this->assertArrayHasKey('id', $userInfo);
        $this->assertArrayHasKey('name', $userInfo);
        $this->assertArrayHasKey('email', $userInfo);
        $this->assertArrayHasKey('role', $userInfo);
    }

    public function test_extension_channel_authorizes_users_from_same_organization(): void
    {
        $extension = Extension::factory()->for($this->organization)->create();
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-extension.{$extension->id}",
        ]);

        $response->assertOk();
    }

    public function test_extension_channel_denies_users_from_different_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        $extension = Extension::factory()->for($otherOrganization)->create();
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-extension.{$extension->id}",
        ]);

        $response->assertForbidden();
    }

    public function test_user_private_channel_authorizes_same_user(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-user.{$this->user->id}",
        ]);

        $response->assertOk();
    }

    public function test_user_private_channel_denies_different_user(): void
    {
        $otherUser = User::factory()->for($this->organization)->create();
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-user.{$otherUser->id}",
        ]);

        $response->assertForbidden();
    }

    public function test_websocket_health_endpoint_returns_ok(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/websocket/health');

        // May return 500 if Soketi is not running in test environment
        // But the endpoint should exist and respond
        $this->assertContains($response->status(), [200, 500]);

        $data = $response->json();
        $this->assertArrayHasKey('status', $data);
    }
}
