<?php

declare(strict_types=1);

namespace Tests\Feature\Supervisor;

use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SupervisorCallQueueAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $supervisor;

    private CallQueue $assignedQueue;

    private CallQueue $ownQueue;

    private CallQueue $otherQueue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->supervisor = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::SUPERVISOR]);
        $agent = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);
        $stranger = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);
        $this->supervisor->supervisedUsers()->attach($agent->id, ['organization_id' => $this->org->id]);

        $this->assignedQueue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $this->ownQueue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $this->otherQueue = CallQueue::factory()->create(['organization_id' => $this->org->id]);

        foreach ([[$this->assignedQueue, $agent], [$this->ownQueue, $this->supervisor], [$this->otherQueue, $stranger]] as [$queue, $user]) {
            CallQueueAgent::factory()->create(['organization_id' => $this->org->id, 'call_queue_id' => $queue->id, 'user_id' => $user->id]);
        }
    }

    public function test_supervisor_lists_only_queues_with_assigned_or_own_agents(): void
    {
        $ids = collect($this->actingAs($this->supervisor)->getJson('/api/v1/call-queues')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->assignedQueue->id, $this->ownQueue->id], $ids);
    }

    public function test_supervisor_opens_in_scope_queue_and_its_live_status(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->actingAs($this->supervisor)->getJson("/api/v1/call-queues/{$this->assignedQueue->id}")->assertOk();
        $this->actingAs($this->supervisor)->getJson("/api/v1/call-queues/{$this->assignedQueue->id}/live")->assertOk();
    }

    public function test_supervisor_cannot_open_out_of_scope_queue(): void
    {
        $this->actingAs($this->supervisor)->getJson("/api/v1/call-queues/{$this->otherQueue->id}")->assertForbidden();
        $this->actingAs($this->supervisor)->getJson("/api/v1/call-queues/{$this->otherQueue->id}/live")->assertForbidden();
        $this->actingAs($this->supervisor)->getJson("/api/v1/call-queues/{$this->otherQueue->id}/stats")->assertForbidden();
    }

    public function test_supervisor_cannot_write(): void
    {
        $this->actingAs($this->supervisor)->postJson('/api/v1/call-queues', ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->supervisor)->putJson("/api/v1/call-queues/{$this->assignedQueue->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->supervisor)->deleteJson("/api/v1/call-queues/{$this->assignedQueue->id}")->assertForbidden();
        $this->actingAs($this->supervisor)->postJson("/api/v1/call-queues/{$this->assignedQueue->id}/toggle-status")->assertForbidden();
        $this->assertDatabaseHas('call_queues', ['id' => $this->assignedQueue->id]);
    }

    public function test_supervisor_cannot_change_another_agents_state(): void
    {
        $agentId = CallQueueAgent::where('call_queue_id', $this->assignedQueue->id)->value('user_id');

        $this->actingAs($this->supervisor)
            ->postJson("/api/v1/call-queues/{$this->assignedQueue->id}/agents/me/state", ['state' => 'logged_out', 'user_id' => $agentId])
            ->assertForbidden();
    }

    public function test_owner_sees_every_queue(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]);

        $this->actingAs($owner)->getJson('/api/v1/call-queues')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($owner)->getJson("/api/v1/call-queues/{$this->otherQueue->id}")->assertOk();
    }
}
