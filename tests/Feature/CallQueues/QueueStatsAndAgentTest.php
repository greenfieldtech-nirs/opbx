<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Enums\QueueCallDisposition;
use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\Organization;
use App\Models\QueueCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Queue agent state endpoints and statistics endpoints.
 */
class QueueStatsAndAgentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $agent;

    private CallQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
        $this->agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);
        $this->queue = CallQueue::factory()->create(['organization_id' => $this->organization->id]);
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'user_id' => $this->agent->id,
        ]);
    }

    public function test_agent_can_set_available_state_with_seeded_counters(): void
    {
        QueueCall::factory()->answered($this->agent, waitSeconds: 10, handleSeconds: 120)->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'entered_at' => now()->subHour(),
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        Sanctum::actingAs($this->agent);

        $this->postJson("/api/v1/call-queues/{$this->queue->id}/agents/me/state", [
            'state' => 'available',
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/agents/state')
            && $request['state'] === 'available'
            && $request['talkSecondsTotal'] === 120
            && $request['callsHandledTotal'] === 1);
    }

    public function test_non_agent_cannot_set_state(): void
    {
        $outsider = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);

        Sanctum::actingAs($outsider);

        $this->postJson("/api/v1/call-queues/{$this->queue->id}/agents/me/state", [
            'state' => 'available',
        ])->assertForbidden();
    }

    public function test_my_queues_returns_memberships_with_state(): void
    {
        Http::fake(['http://acd-worker:8084/*' => Http::response([
            'waiting' => [],
            'agents' => [['userId' => (string) $this->agent->id, 'state' => 'AVAILABLE']],
        ], 200)]);

        Sanctum::actingAs($this->agent);

        $response = $this->getJson('/api/v1/call-queues/agents/me');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $this->queue->id)
            ->assertJsonPath('data.0.state', 'AVAILABLE');
    }

    public function test_stats_computes_duration_aggregates(): void
    {
        $base = [
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'entered_at' => now()->subHours(2),
        ];
        QueueCall::factory()->answered($this->agent, waitSeconds: 30, handleSeconds: 100)->create($base);
        QueueCall::factory()->answered($this->agent, waitSeconds: 90, handleSeconds: 300)->create($base);
        QueueCall::factory()->abandoned(waitSeconds: 45)->create($base);
        QueueCall::factory()->create([
            ...$base,
            'disposition' => QueueCallDisposition::OVERFLOW,
            'entered_at' => now()->subHours(2),
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/v1/call-queues/{$this->queue->id}/stats");

        $response->assertOk()
            ->assertJsonPath('data.waiting_time.min', 30)
            ->assertJsonPath('data.waiting_time.max', 90)
            ->assertJsonPath('data.waiting_time.avg', 60)
            ->assertJsonPath('data.handling_time.min', 100)
            ->assertJsonPath('data.handling_time.max', 300)
            ->assertJsonPath('data.handling_time.avg', 200)
            ->assertJsonPath('data.totals.handled', 2)
            ->assertJsonPath('data.totals.abandoned', 1)
            ->assertJsonPath('data.totals.overflowed', 1);

        // Sample stddev of [30, 90] = 30 (MySQL STDDEV is population stddev: 30).
        $this->assertEqualsWithDelta(30.0, $response->json('data.waiting_time.stddev'), 0.01);
        // Population stddev of [100, 300] = 100.
        $this->assertEqualsWithDelta(100.0, $response->json('data.handling_time.stddev'), 0.01);
    }

    public function test_live_combines_worker_snapshot_with_rolling_counts(): void
    {
        QueueCall::factory()->answered($this->agent)->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'entered_at' => now()->subMinutes(10),
        ]);
        QueueCall::factory()->abandoned()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'entered_at' => now()->subMinutes(100),
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([
            'waiting' => [['callId' => 'c1', 'position' => 1, 'waitedSeconds' => 42]],
            'agents' => [['userId' => (string) $this->agent->id, 'state' => 'WRAP_UP']],
        ], 200)]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/v1/call-queues/{$this->queue->id}/live");

        $response->assertOk()
            ->assertJsonPath('data.waiting.0.callId', 'c1')
            ->assertJsonPath('data.agents.0.state', 'WRAP_UP')
            ->assertJsonPath('data.rolling.handled_15m', 1)
            ->assertJsonPath('data.rolling.handled_60m', 1)
            ->assertJsonPath('data.rolling.abandoned_15m', 0)
            ->assertJsonPath('data.rolling.abandoned_24h', 1);
    }

    public function test_queue_calls_index_filters_and_paginates(): void
    {
        QueueCall::factory()->count(3)->answered($this->agent)->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
        ]);
        QueueCall::factory()->abandoned()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
        ]);
        QueueCall::factory()->create(); // other org

        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/v1/queue-calls?disposition=answered');

        $response->assertOk()->assertJsonPath('meta.total', 3);

        $this->getJson('/api/v1/queue-calls?queue_id='.$this->queue->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 4);
    }

    public function test_queue_calls_csv_export(): void
    {
        QueueCall::factory()->answered($this->agent, waitSeconds: 15, handleSeconds: 60)->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->get('/api/v1/queue-calls/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('Disposition', $content);
        $this->assertStringContainsString('answered', $content);
        $this->assertStringContainsString('15', $content);
    }
}
