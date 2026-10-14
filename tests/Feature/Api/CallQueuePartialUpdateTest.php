<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An update (PUT or PATCH) changes only the fields it sends; nothing else is defaulted or reset.
 */
class CallQueuePartialUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]));
    }

    private function extension(): Extension
    {
        return Extension::factory()->create(['organization_id' => $this->organization->id]);
    }

    private function customQueue(array $overrides = []): CallQueue
    {
        $queue = CallQueue::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'description' => 'Night shift',
            'strategy' => 'round_robin',
            'agent_ring_timeout' => 45,
            'max_wait_seconds' => 120,
            'wrap_up_seconds' => 60,
            'announce_position' => true,
            'announce_position_timeout' => 90,
            'announce_position_language' => 'fr-FR',
            'immediate_connect' => true,
            'conference_ring_all' => true,
            'fallback_action' => 'extension',
            'fallback_extension_id' => $this->extension()->id,
            'status' => 'inactive',
        ], $overrides));

        $agent = User::factory()->create(['organization_id' => $this->organization->id]);
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $queue->id,
            'user_id' => $agent->id,
        ]);

        return $queue;
    }

    /**
     * @return array<string, mixed>
     */
    private function columnsExcept(CallQueue $queue, string ...$except): array
    {
        return collect($queue->fresh()->getAttributes())->except(['updated_at', ...$except])->all();
    }

    public function test_patch_with_only_a_name_changes_nothing_else(): void
    {
        $queue = $this->customQueue();
        $before = $this->columnsExcept($queue, 'name');
        $agents = CallQueueAgent::where('call_queue_id', $queue->id)->pluck('user_id')->all();

        $this->patchJson("/api/v1/call-queues/{$queue->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->assertSame($before, $this->columnsExcept($queue, 'name'));
        $this->assertSame($agents, CallQueueAgent::where('call_queue_id', $queue->id)->pluck('user_id')->all());
    }

    public function test_put_with_some_fields_leaves_the_others_alone(): void
    {
        $queue = $this->customQueue();
        $before = $this->columnsExcept($queue, 'max_wait_seconds');

        $this->putJson("/api/v1/call-queues/{$queue->id}", ['max_wait_seconds' => 200])->assertOk();

        $this->assertSame(200, $queue->fresh()->max_wait_seconds);
        $this->assertSame($before, $this->columnsExcept($queue, 'max_wait_seconds'));
    }

    public function test_patch_fallback_action_alone_keeps_the_stored_target(): void
    {
        $queue = $this->customQueue();
        $target = $queue->fallback_extension_id;

        $this->patchJson("/api/v1/call-queues/{$queue->id}", ['fallback_action' => 'extension'])->assertOk();

        $this->assertSame($target, $queue->fresh()->fallback_extension_id);
    }

    public function test_patch_fallback_action_alone_needs_a_target_when_none_is_stored(): void
    {
        $queue = $this->customQueue(['fallback_action' => 'hangup', 'fallback_extension_id' => null]);

        $this->patchJson("/api/v1/call-queues/{$queue->id}", ['fallback_action' => 'extension'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fallback_extension_id');

        $this->assertSame('hangup', $queue->fresh()->fallback_action->value);
    }

    public function test_patch_fallback_target_alone_uses_the_stored_action(): void
    {
        $queue = $this->customQueue();
        $newTarget = $this->extension();

        $this->patchJson("/api/v1/call-queues/{$queue->id}", ['fallback_extension_id' => $newTarget->id])->assertOk();

        $this->assertSame($newTarget->id, $queue->fresh()->fallback_extension_id);
    }

    public function test_patch_fallback_target_alone_is_checked_against_the_stored_action(): void
    {
        $queue = $this->customQueue();
        $inactive = Extension::factory()->inactive()->create(['organization_id' => $this->organization->id]);

        $this->patchJson("/api/v1/call-queues/{$queue->id}", ['fallback_extension_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fallback_extension_id');
    }

    public function test_patch_fallback_action_hangup_clears_the_target(): void
    {
        $queue = $this->customQueue();

        $this->patchJson("/api/v1/call-queues/{$queue->id}", ['fallback_action' => 'hangup'])->assertOk();

        $this->assertNull($queue->fresh()->fallback_extension_id);
    }

    public function test_patch_cannot_blank_a_required_field(): void
    {
        $queue = $this->customQueue();

        $this->patchJson("/api/v1/call-queues/{$queue->id}", ['strategy' => null, 'agents' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['strategy', 'agents']);
    }
}
