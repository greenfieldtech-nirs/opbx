<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Enums\CallQueueStatus;
use App\Enums\CallQueueStrategy;
use App\Enums\QueueCallDisposition;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\QueueCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Call queue model, factory, and tenant-scoping smoke tests.
 */
class QueueModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_queue_factory_and_relations(): void
    {
        $queue = CallQueue::factory()->create([
            'strategy' => CallQueueStrategy::LEAST_TALK_TIME,
            'status' => CallQueueStatus::ACTIVE,
        ]);

        $agent = User::factory()->create(['organization_id' => $queue->organization_id]);
        CallQueueAgent::factory()->create([
            'organization_id' => $queue->organization_id,
            'call_queue_id' => $queue->id,
            'user_id' => $agent->id,
        ]);

        $this->actingAs($agent);

        $this->assertSame(CallQueueStrategy::LEAST_TALK_TIME, $queue->strategy);
        $this->assertTrue($queue->isActive());
        $this->assertCount(1, $queue->agents);
        $this->assertSame($agent->id, $queue->agents->first()->id);
        $this->assertSame($queue->id, $queue->agentRecords->first()->call_queue_id);
    }

    public function test_queue_call_factory_answered_disposition(): void
    {
        $queue = CallQueue::factory()->create();
        $agent = User::factory()->create(['organization_id' => $queue->organization_id]);

        $call = QueueCall::factory()->answered($agent, waitSeconds: 45, handleSeconds: 90)->create([
            'organization_id' => $queue->organization_id,
            'call_queue_id' => $queue->id,
        ]);

        $this->actingAs($agent);

        $this->assertSame(QueueCallDisposition::ANSWERED, $call->disposition);
        $this->assertSame(45, $call->waiting_seconds);
        $this->assertSame(90, $call->handling_seconds);
        $this->assertSame($agent->id, $call->agent->id);
    }

    public function test_queue_call_factory_abandoned_disposition(): void
    {
        $call = QueueCall::factory()->abandoned(waitSeconds: 120)->create();

        $this->assertSame(QueueCallDisposition::ABANDONED, $call->disposition);
        $this->assertSame(120, $call->waiting_seconds);
        $this->assertNull($call->agent_user_id);
    }

    public function test_organization_scope_isolates_queues(): void
    {
        $queue = CallQueue::factory()->create();
        CallQueue::factory()->create();

        $user = User::factory()->create(['organization_id' => $queue->organization_id]);
        $this->actingAs($user);

        $this->assertCount(1, CallQueue::all());
        $this->assertSame($queue->id, CallQueue::first()->id);
    }

    public function test_pending_scope_filters_unresolved_calls(): void
    {
        $queue = CallQueue::factory()->create();
        QueueCall::factory()->create(['call_queue_id' => $queue->id, 'organization_id' => $queue->organization_id]);
        QueueCall::factory()->abandoned()->create(['call_queue_id' => $queue->id, 'organization_id' => $queue->organization_id]);

        $user = User::factory()->create(['organization_id' => $queue->organization_id]);
        $this->actingAs($user);

        $this->assertCount(1, QueueCall::pending()->get());
    }
}
