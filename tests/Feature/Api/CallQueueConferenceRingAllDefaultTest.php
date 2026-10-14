<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** "Ring Agents in Separate Calls" is on by default for new Ring All queues, and stays switchable. */
final class CallQueueConferenceRingAllDefaultTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_15_110000_enable_conference_ring_all_on_ring_all_queues.php';

    private Organization $org;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]));
        $this->agent = User::factory()->create(['organization_id' => $this->org->id]);
        Extension::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->agent->id]);
    }

    /** @param  array<string, mixed>  $extra */
    private function create(string $strategy, array $extra = []): bool
    {
        return $this->postJson('/api/v1/call-queues', ['name' => 'Q '.$strategy.count($extra), 'strategy' => $strategy, 'status' => 'active', 'agents' => [$this->agent->id]] + $extra)
            ->assertCreated()->json('data.conference_ring_all');
    }

    public function test_new_ring_all_queue_rings_agents_in_separate_calls(): void
    {
        $this->assertTrue($this->create('ring_all'));
    }

    public function test_omitted_strategy_is_ring_all_and_gets_the_default(): void
    {
        $id = $this->postJson('/api/v1/call-queues', ['name' => 'Queue', 'status' => 'active', 'agents' => [$this->agent->id]])->assertCreated()->json('data.id');

        $this->assertTrue(CallQueue::find($id)->conference_ring_all);
    }

    public function test_other_strategies_are_not_switched_on(): void
    {
        $this->assertFalse($this->create('round_robin'));
    }

    public function test_admin_can_switch_it_off_and_an_update_keeps_it(): void
    {
        $this->assertFalse($this->create('ring_all', ['conference_ring_all' => false]));

        $queue = CallQueue::where('organization_id', $this->org->id)->firstOrFail();
        $this->patchJson("/api/v1/call-queues/{$queue->id}", ['max_wait_seconds' => 120])->assertOk();
        $this->assertFalse($queue->fresh()->conference_ring_all);
    }

    public function test_migration_turns_it_on_for_existing_ring_all_queues_only(): void
    {
        $ringAll = CallQueue::factory()->create(['organization_id' => $this->org->id, 'strategy' => 'ring_all', 'conference_ring_all' => false]);
        $roundRobin = CallQueue::factory()->create(['organization_id' => $this->org->id, 'strategy' => 'round_robin', 'conference_ring_all' => false]);

        $migration = include database_path('migrations/'.self::MIGRATION);
        $migration->up();
        $migration->up();

        $this->assertTrue($ringAll->fresh()->conference_ring_all);
        $this->assertFalse($roundRobin->fresh()->conference_ring_all);
    }
}
