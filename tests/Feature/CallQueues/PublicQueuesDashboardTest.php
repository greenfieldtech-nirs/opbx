<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\Organization;
use App\Models\QueueCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public queues dashboard: token-authed wallboard endpoints + link management.
 */
class PublicQueuesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private CallQueue $queue;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        $this->organization->public_queues_token = (string) Str::uuid();
        $this->organization->save();

        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
        $this->queue = CallQueue::factory()->create(['organization_id' => $this->organization->id]);
        $this->token = (string) $this->organization->public_queues_token;
    }

    public function test_public_queues_lists_org_queues_without_auth(): void
    {
        CallQueue::factory()->create(); // other org

        $response = $this->getJson("/api/v1/public/queues-dashboard/{$this->token}/queues");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->queue->id)
            ->assertJsonPath('data.0.name', $this->queue->name);
    }

    public function test_public_live_returns_snapshot_for_org_queue(): void
    {
        QueueCall::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'call_id' => 'wallboard-1',
            'from_number' => '+15551234567',
        ]);

        // Regression: without an authenticated org context the tenant scope
        // must be bypassed or agents disappear from the public snapshot.
        $agent = User::factory()->create(['organization_id' => $this->organization->id]);
        \App\Models\CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'user_id' => $agent->id,
        ]);
        \App\Models\Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $agent->id,
            'extension_number' => '3001',
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([
            'waiting' => [['callId' => 'wallboard-1', 'position' => 1, 'waitedSeconds' => 12]],
            'agents' => [['userId' => (string) $agent->id, 'extensionNumber' => '3001', 'state' => 'AVAILABLE']],
        ], 200)]);

        $response = $this->getJson("/api/v1/public/queues-dashboard/{$this->token}/queues/{$this->queue->id}/live");

        $response->assertOk()
            ->assertJsonPath('data.call_queue_name', $this->queue->name)
            ->assertJsonPath('data.waiting.0.callId', 'wallboard-1')
            ->assertJsonPath('data.waiting.0.from_number', '+15551234567')
            ->assertJsonPath('data.agents.0.extensionNumber', '3001');
    }

    public function test_public_endpoints_reject_invalid_token_and_foreign_queue(): void
    {
        $this->getJson('/api/v1/public/queues-dashboard/not-a-real-token/queues')->assertNotFound();

        $foreignQueue = CallQueue::factory()->create();
        $this->getJson("/api/v1/public/queues-dashboard/{$this->token}/queues/{$foreignQueue->id}/live")
            ->assertNotFound();
    }

    public function test_owner_can_fetch_and_regenerate_link(): void
    {
        Sanctum::actingAs($this->owner);

        $show = $this->getJson('/api/v1/queues-dashboard/link');
        $show->assertOk()->assertJsonStructure(['data' => ['url']]);
        $this->assertStringContainsString('/public/queues-dashboard/', $show->json('data.url'));

        $regenerate = $this->postJson('/api/v1/queues-dashboard/link/regenerate');
        $regenerate->assertOk();
        $this->assertNotSame($show->json('data.url'), $regenerate->json('data.url'));

        // Old token is revoked, new one works.
        $this->getJson('/api/v1/public/queues-dashboard/'.$this->token.'/queues')->assertNotFound();
        $newToken = basename(parse_url($regenerate->json('data.url'), PHP_URL_PATH));
        $this->getJson("/api/v1/public/queues-dashboard/{$newToken}/queues")->assertOk();
    }

    public function test_pbx_user_cannot_manage_link(): void
    {
        $pbxUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);

        Sanctum::actingAs($pbxUser);

        $this->getJson('/api/v1/queues-dashboard/link')->assertForbidden();
        $this->postJson('/api/v1/queues-dashboard/link/regenerate')->assertForbidden();
    }
}
