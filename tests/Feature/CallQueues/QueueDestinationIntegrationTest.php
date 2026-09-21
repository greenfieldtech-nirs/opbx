<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenuOption;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use App\Services\VoiceRouting\InboundRoutingService;
use App\Services\VoiceRouting\Strategies\RingGroupRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Call queue integration as a destination type: DID routing, IVR options,
 * ring group fallback, and business hours targets.
 */
class QueueDestinationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CallQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://test.example.com',
        ]);

        $this->queue = CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'moh_recording_id' => null,
        ]);
    }

    public function test_did_routing_type_determines_queue_strategy(): void
    {
        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'call_queue',
            'routing_config' => ['call_queue_id' => $this->queue->id],
        ]);

        $service = app(InboundRoutingService::class);
        $type = $service->determineExtensionType($did, ['call_queue' => $this->queue]);

        $this->assertSame(ExtensionType::QUEUE, $type);
        $this->assertSame($this->queue->id, $did->getCallQueueAttribute()->id);
    }

    public function test_ivr_option_resolves_call_queue_destination(): void
    {
        $ivrMenu = \App\Models\IvrMenu::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $option = new IvrMenuOption([
            'ivr_menu_id' => $ivrMenu->id,
            'input_digits' => '1',
            'destination_type' => 'call_queue',
            'destination_id' => $this->queue->id,
        ]);
        $option->save();

        $resolved = $option->getDestinationWithFallback($ivrMenu);

        $this->assertInstanceOf(CallQueue::class, $resolved);
        $this->assertSame($this->queue->id, $resolved->id);
    }

    public function test_ring_group_fallback_to_call_queue_enqueues_caller(): void
    {
        Http::fake(['http://acd-worker:8084/*' => Http::response(['position' => 1], 200)]);

        $agent = User::factory()->create(['organization_id' => $this->organization->id]);
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'user_id' => $agent->id,
        ]);

        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => 'simultaneous',
            'fallback_action' => RingGroupFallbackAction::CALL_QUEUE,
            'fallback_call_queue_id' => $this->queue->id,
        ]);

        $request = Request::create('/callbacks/voice/ring-group-callback', 'POST', [
            'CallSid' => 'call-rg-fallback-1',
            'From' => '+15551234567',
            'To' => '8000',
            'ring_group_id' => $ringGroup->id,
            'attempt_number' => 0,
            'callback_type' => 'ring_group_fallback',
            '_organization_id' => $this->organization->id,
        ]);

        $response = app(RingGroupRoutingStrategy::class)->route($request, new DidNumber, [
            'ring_group' => $ringGroup,
        ]);

        $content = (string) $response->getContent();
        $this->assertStringContainsString('queue-poll', $content);

        // The caller was enqueued into the queue.
        $this->assertDatabaseHas('queue_calls', [
            'call_queue_id' => $this->queue->id,
            'call_id' => 'call-rg-fallback-1',
        ]);
    }

    public function test_business_hours_parse_target_id_supports_queue_prefix(): void
    {
        $parsed = \App\Models\BusinessHoursSchedule::parseTargetId('queue-42');

        $this->assertSame(['type' => 'call_queue', 'id' => 42], $parsed);
    }

    public function test_phone_number_request_accepts_call_queue_routing(): void
    {
        $owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/phone-numbers', [
            'phone_number' => '+15557654321',
            'routing_type' => 'call_queue',
            'routing_config' => ['call_queue_id' => $this->queue->id],
        ])->assertCreated()->assertJsonPath('data.routing_type', 'call_queue');

        // Cross-organization queue is rejected.
        $foreignQueue = CallQueue::factory()->create();
        $this->postJson('/api/v1/phone-numbers', [
            'phone_number' => '+15557654322',
            'routing_type' => 'call_queue',
            'routing_config' => ['call_queue_id' => $foreignQueue->id],
        ])->assertUnprocessable();
    }

    public function test_ivr_menu_request_accepts_call_queue_option(): void
    {
        $owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/ivr-menus', [
            'name' => 'Main Menu',
            'description' => 'Test',
            'audio_file_path' => 'https://example.com/greeting.wav',
            'timeout' => 10,
            'max_retries' => 3,
            'failover_destination_type' => 'call_queue',
            'failover_destination_id' => $this->queue->id,
            'status' => 'active',
            'options' => [
                [
                    'input_digits' => '1',
                    'destination_type' => 'call_queue',
                    'destination_id' => $this->queue->id,
                    'priority' => 1,
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('ivr_menu_options', [
            'destination_type' => 'call_queue',
            'destination_id' => $this->queue->id,
        ]);
    }

    public function test_queue_delete_protected_when_referenced_by_did(): void
    {
        DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'call_queue',
            'routing_config' => ['call_queue_id' => $this->queue->id],
        ]);

        $owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/call-queues/{$this->queue->id}")->assertStatus(500);
    }
}
