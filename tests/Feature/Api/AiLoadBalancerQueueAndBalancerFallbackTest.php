<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\User;
use App\Services\VoiceRouting\Strategies\AiLoadBalancerRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AiLoadBalancerQueueAndBalancerFallbackTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        CloudonixSettings::factory()->create(['organization_id' => $this->org->id, 'webhook_base_url' => 'https://example.com']);
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]));
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        $assistant = AiAssistant::factory()->create(['organization_id' => $this->org->id]);

        return $extra + [
            'name' => 'LB '.uniqid(),
            'strategy' => 'round_robin',
            'status' => 'active',
            'members' => [['ai_assistant_id' => $assistant->id]],
        ];
    }

    public function test_store_requires_and_scopes_the_queue_and_balancer_targets(): void
    {
        $this->postJson('/api/v1/ai-assistant-load-balancers', $this->payload(['fallback_action' => 'call_queue']))
            ->assertStatus(422)->assertJsonValidationErrors('fallback_call_queue_id');
        $this->postJson('/api/v1/ai-assistant-load-balancers', $this->payload(['fallback_action' => 'ai_load_balancer']))
            ->assertStatus(422)->assertJsonValidationErrors('fallback_ai_load_balancer_id');

        $foreignQueue = CallQueue::factory()->create(['organization_id' => $this->other->id]);
        $foreignLb = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->other->id]);
        $this->postJson('/api/v1/ai-assistant-load-balancers', $this->payload(['fallback_action' => 'call_queue', 'fallback_call_queue_id' => $foreignQueue->id]))
            ->assertStatus(422)->assertJsonValidationErrors('fallback_call_queue_id');
        $this->postJson('/api/v1/ai-assistant-load-balancers', $this->payload(['fallback_action' => 'ai_load_balancer', 'fallback_ai_load_balancer_id' => $foreignLb->id]))
            ->assertStatus(422)->assertJsonValidationErrors('fallback_ai_load_balancer_id');

        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $this->postJson('/api/v1/ai-assistant-load-balancers', $this->payload(['fallback_action' => 'call_queue', 'fallback_call_queue_id' => $queue->id]))
            ->assertCreated()->assertJsonPath('data.fallback_call_queue_id', $queue->id);
    }

    public function test_balancer_cannot_fall_back_to_itself_and_only_the_chosen_target_is_kept(): void
    {
        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $target = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id]);
        $lb = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->org->id,
            'fallback_action' => 'call_queue',
            'fallback_call_queue_id' => $queue->id,
        ]);

        $this->putJson("/api/v1/ai-assistant-load-balancers/{$lb->id}", $this->payload(['fallback_action' => 'ai_load_balancer', 'fallback_ai_load_balancer_id' => $lb->id]))
            ->assertStatus(422)->assertJsonValidationErrors('fallback_ai_load_balancer_id');

        $this->putJson("/api/v1/ai-assistant-load-balancers/{$lb->id}", $this->payload(['fallback_action' => 'ai_load_balancer', 'fallback_ai_load_balancer_id' => $target->id]))
            ->assertOk();
        $fresh = $lb->fresh();
        $this->assertSame($target->id, $fresh->fallback_ai_load_balancer_id);
        $this->assertNull($fresh->fallback_call_queue_id);
    }

    public function test_status_only_update_keeps_members(): void
    {
        $lb = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id, 'status' => 'active']);

        $this->putJson("/api/v1/ai-assistant-load-balancers/{$lb->id}", ['status' => 'inactive'])->assertOk();

        $this->assertSame('inactive', $lb->fresh()->status->value);
    }

    public function test_in_use_queue_and_balancer_deletes_are_blocked_by_the_fallback(): void
    {
        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $target = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id]);
        $lb = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->org->id,
            'fallback_action' => 'call_queue',
            'fallback_call_queue_id' => $queue->id,
            'fallback_ai_load_balancer_id' => $target->id,
        ]);

        $this->deleteJson("/api/v1/call-queues/{$queue->id}")->assertStatus(409)->assertJsonFragment(['id' => $lb->id]);
        $this->deleteJson("/api/v1/ai-assistant-load-balancers/{$target->id}")->assertStatus(409)->assertJsonFragment(['id' => $lb->id]);
    }

    public function test_update_refuses_fallback_loops_naming_the_cycle(): void
    {
        $c = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id, 'name' => 'LB-C']);
        $b = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id, 'name' => 'LB-B', 'fallback_action' => 'ai_load_balancer', 'fallback_ai_load_balancer_id' => $c->id]);
        $a = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id, 'name' => 'LB-A', 'fallback_action' => 'ai_load_balancer', 'fallback_ai_load_balancer_id' => $b->id]);

        // C -> A would close A -> B -> C -> A.
        $response = $this->putJson("/api/v1/ai-assistant-load-balancers/{$c->id}", $this->payload(['fallback_action' => 'ai_load_balancer', 'fallback_ai_load_balancer_id' => $a->id]))
            ->assertStatus(422)->assertJsonValidationErrors('fallback_ai_load_balancer_id');
        $this->assertStringContainsString('LB-C -> LB-A -> LB-B -> LB-C', json_encode($response->json('errors.fallback_ai_load_balancer_id')));
        $this->assertNull($c->fresh()->fallback_ai_load_balancer_id);

        // A non-looping chain is still accepted.
        $d = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id]);
        $this->putJson("/api/v1/ai-assistant-load-balancers/{$c->id}", $this->payload(['fallback_action' => 'ai_load_balancer', 'fallback_ai_load_balancer_id' => $d->id]))->assertOk();
    }

    private function fallback(AiAssistantLoadBalancer $lb): string
    {
        $method = new \ReflectionMethod(AiLoadBalancerRoutingStrategy::class, 'handleFallback');

        return (string) $method->invoke(app(AiLoadBalancerRoutingStrategy::class), $lb->fresh(), Request::create('/voice', 'POST', ['CallSid' => 'CA1', '_organization_id' => $this->org->id, 'From' => '+15550001', 'To' => '+15550002']))->getContent();
    }

    public function test_routing_falls_back_to_the_queue_or_the_balancer_and_survives_loops(): void
    {
        $inactiveQueue = CallQueue::factory()->inactive()->create(['organization_id' => $this->org->id]);
        $lb = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->org->id,
            'fallback_action' => 'call_queue',
            'fallback_call_queue_id' => $inactiveQueue->id,
        ]);
        $this->assertStringContainsString('No AI assistants available', $this->fallback($lb));

        Http::fake(['*' => Http::response(['ok' => true])]);
        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $lb->update(['fallback_call_queue_id' => $queue->id]);
        $xml = $this->fallback($lb);
        $this->assertStringNotContainsString('No AI assistants available', $xml);
        $this->assertStringContainsString('<Response', $xml);

        // A -> B -> A (no members anywhere): ends in a hangup instead of recursing forever.
        $b = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id, 'fallback_action' => 'ai_load_balancer']);
        $a = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id, 'fallback_action' => 'ai_load_balancer', 'fallback_ai_load_balancer_id' => $b->id]);
        $b->update(['fallback_ai_load_balancer_id' => $a->id]);
        $this->assertStringContainsString('No AI assistants available', $this->fallback($a));
    }
}
