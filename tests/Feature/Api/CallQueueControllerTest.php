<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\CallQueueStrategy;
use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Call Queues API endpoints test suite.
 *
 * Tests CRUD operations, tenant isolation, agent validation, MOH validation,
 * and fallback normalization for call queues.
 */
class CallQueueControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $admin;

    private User $pbxUser;

    private User $otherOrgOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Test Org']);
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_ADMIN,
        ]);
        $this->pbxUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);
        $this->otherOrgOwner = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'role' => UserRole::OWNER,
        ]);
    }

    private function makeAgentWithExtension(): User
    {
        $agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $agent->id,
        ]);

        return $agent;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Support Queue',
            'strategy' => CallQueueStrategy::RING_ALL->value,
            'agents' => [$this->makeAgentWithExtension()->id],
        ], $overrides);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/call-queues')->assertUnauthorized();
    }

    public function test_owner_can_list_queues_scoped_to_organization(): void
    {
        CallQueue::factory()->count(2)->create(['organization_id' => $this->organization->id]);
        CallQueue::factory()->create();

        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/call-queues');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_store_creates_queue_with_agents(): void
    {
        $agent = $this->makeAgentWithExtension();

        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/call-queues', $this->validPayload([
            'agents' => [$agent->id],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Support Queue')
            ->assertJsonPath('data.strategy', 'ring_all')
            ->assertJsonPath('data.agents.0.id', $agent->id);

        $this->assertDatabaseHas('call_queue_agents', [
            'call_queue_id' => $response->json('data.id'),
            'user_id' => $agent->id,
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_store_rejects_agent_without_extension(): void
    {
        $agentWithoutExtension = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/call-queues', $this->validPayload([
            'agents' => [$agentWithoutExtension->id],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['agents']);
    }

    public function test_store_rejects_agent_from_other_organization(): void
    {
        $foreignUser = User::factory()->create([
            'organization_id' => $this->otherOrgOwner->organization_id,
        ]);

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/call-queues', $this->validPayload([
            'agents' => [$foreignUser->id],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['agents']);
    }

    public function test_store_rejects_recording_not_tagged_as_moh(): void
    {
        $recording = Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'is_moh' => false,
        ]);

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/call-queues', $this->validPayload([
            'moh_recording_id' => $recording->id,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['moh_recording_id']);
    }

    public function test_store_defaults_null_announce_timeout(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/call-queues', $this->validPayload([
            'announce_position' => false,
            'announce_position_timeout' => null,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.announce_position_timeout', 60);
    }

    public function test_store_accepts_announce_position_with_default_language(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/call-queues', $this->validPayload([
            'announce_position' => true,
            'announce_position_timeout' => 30,
            // No language: "Default" is a valid choice.
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.announce_position', true)
            ->assertJsonPath('data.announce_position_language', null);
    }

    public function test_store_accepts_moh_tagged_recording(): void
    {
        $recording = Recording::factory()->create([
            'organization_id' => $this->organization->id,
            'is_moh' => true,
        ]);

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/call-queues', $this->validPayload([
            'moh_recording_id' => $recording->id,
        ]))->assertCreated()
            ->assertJsonPath('data.moh_recording_id', $recording->id);
    }

    public function test_store_normalizes_fallback_fields(): void
    {
        $fallbackExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/call-queues', $this->validPayload([
            'fallback_action' => 'extension',
            'fallback_extension_id' => $fallbackExtension->id,
        ]));

        $response->assertCreated();

        $queue = CallQueue::findOrFail($response->json('data.id'));
        $this->assertSame($fallbackExtension->id, $queue->fallback_extension_id);
        $this->assertNull($queue->fallback_ring_group_id);
    }

    public function test_pbx_user_cannot_create_queue(): void
    {
        Sanctum::actingAs($this->pbxUser);

        $this->postJson('/api/v1/call-queues', $this->validPayload())
            ->assertForbidden();
    }

    public function test_other_org_owner_cannot_view_queue(): void
    {
        $queue = CallQueue::factory()->create(['organization_id' => $this->organization->id]);

        Sanctum::actingAs($this->otherOrgOwner);

        $this->getJson("/api/v1/call-queues/{$queue->id}")->assertNotFound();
    }

    public function test_update_syncs_agents_and_settings(): void
    {
        $queue = CallQueue::factory()->create(['organization_id' => $this->organization->id]);
        $oldAgent = $this->makeAgentWithExtension();
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $queue->id,
            'user_id' => $oldAgent->id,
        ]);
        $newAgent = $this->makeAgentWithExtension();

        Sanctum::actingAs($this->admin);

        $response = $this->putJson("/api/v1/call-queues/{$queue->id}", [
            'name' => 'Renamed Queue',
            'strategy' => CallQueueStrategy::ROUND_ROBIN->value,
            'agent_ring_timeout' => 30,
            'max_wait_seconds' => 300,
            'wrap_up_seconds' => 15,
            'agents' => [$newAgent->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Renamed Queue')
            ->assertJsonPath('data.strategy', 'round_robin')
            ->assertJsonPath('data.agents.0.id', $newAgent->id);

        $this->assertDatabaseMissing('call_queue_agents', [
            'call_queue_id' => $queue->id,
            'user_id' => $oldAgent->id,
        ]);
        $this->assertDatabaseHas('call_queue_agents', [
            'call_queue_id' => $queue->id,
            'user_id' => $newAgent->id,
        ]);
    }

    public function test_destroy_deletes_queue_and_agents(): void
    {
        $queue = CallQueue::factory()->create(['organization_id' => $this->organization->id]);
        $agent = $this->makeAgentWithExtension();
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $queue->id,
            'user_id' => $agent->id,
        ]);

        Sanctum::actingAs($this->owner);

        $this->deleteJson("/api/v1/call-queues/{$queue->id}")->assertNoContent();

        $this->assertDatabaseMissing('call_queues', ['id' => $queue->id]);
        $this->assertDatabaseMissing('call_queue_agents', ['call_queue_id' => $queue->id]);
    }

    public function test_toggle_status_flips_active_inactive(): void
    {
        $queue = CallQueue::factory()->create(['organization_id' => $this->organization->id]);

        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/call-queues/{$queue->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->postJson("/api/v1/call-queues/{$queue->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        // PBX users cannot toggle.
        Sanctum::actingAs($this->pbxUser);
        $this->postJson("/api/v1/call-queues/{$queue->id}/toggle-status")->assertForbidden();
    }

    public function test_agent_state_endpoint_updates_state(): void
    {
        $queue = CallQueue::factory()->create(['organization_id' => $this->organization->id]);
        $agent = $this->makeAgentWithExtension();
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $queue->id,
            'user_id' => $agent->id,
        ]);

        \Illuminate\Support\Facades\Http::fake(['http://acd-worker:8084/*' => \Illuminate\Support\Facades\Http::response([], 204)]);

        Sanctum::actingAs($agent);

        $this->postJson("/api/v1/call-queues/{$queue->id}/agents/me/state", [
            'state' => 'available',
        ])->assertOk();
    }

    public function test_recordings_moh_filter(): void
    {
        Recording::factory()->create(['organization_id' => $this->organization->id, 'is_moh' => true]);
        Recording::factory()->create(['organization_id' => $this->organization->id, 'is_moh' => false]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/recordings?moh=1');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertCount(1, $ids);
        $this->assertTrue(Recording::findOrFail($ids->first())->is_moh);
    }
}
