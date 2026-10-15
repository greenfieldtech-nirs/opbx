<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\QueueCall;
use App\Models\Recording;
use App\Models\RingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UserDeleteKeepsRecordingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_user_keeps_their_recordings(): void
    {
        $org = Organization::factory()->create();
        $author = User::factory()->create(['organization_id' => $org->id]);
        $recording = Recording::factory()->create(['organization_id' => $org->id, 'created_by' => $author->id, 'updated_by' => $author->id]);

        $author->delete();

        $recording->refresh();
        $this->assertNull($recording->created_by);
        $this->assertNull($recording->updated_by);
    }

    public function test_deleting_referenced_rows_nulls_fallbacks_and_queue_call_agent(): void
    {
        $org = Organization::factory()->create();
        $extension = Extension::factory()->create(['organization_id' => $org->id]);
        $agent = User::factory()->create(['organization_id' => $org->id]);
        $queue = CallQueue::factory()->create(['organization_id' => $org->id, 'fallback_extension_id' => $extension->id]);
        $ringGroup = RingGroup::factory()->create(['organization_id' => $org->id, 'fallback_call_queue_id' => $queue->id]);
        $call = QueueCall::factory()->create(['organization_id' => $org->id, 'call_queue_id' => $queue->id, 'agent_user_id' => $agent->id]);

        $agent->delete();
        $extension->delete();
        $this->assertNull($call->fresh()->agent_user_id);
        $this->assertNull($queue->fresh()->fallback_extension_id);

        $queue->delete();
        $this->assertNull($ringGroup->fresh()->fallback_call_queue_id);
    }

    public function test_foreign_keys_exist(): void
    {
        $columns = fn (string $table) => collect(Schema::getForeignKeys($table))->flatMap(fn ($fk) => $fk['columns'])->all();

        $this->assertContains('fallback_ai_load_balancer_id', $columns('call_queues'));
        $this->assertContains('fallback_call_queue_id', $columns('ring_groups'));
        $this->assertContains('agent_user_id', $columns('queue_calls'));

        $migration = include database_path('migrations/2026_10_12_200200_add_referential_integrity_nullify_on_delete.php');
        $this->assertTrue(method_exists($migration, 'up') && method_exists($migration, 'down'));
    }
}
