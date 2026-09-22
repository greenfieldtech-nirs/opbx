<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * *45{queue_id} dial-in agent login/logout feature code.
 */
class QueueAgentDialInTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CallQueue $queue;

    private User $agent;

    private Extension $agentExtension;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        $this->token = (string) \Illuminate\Support\Str::random(32);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'domain_name' => 'test.example.com',
            'domain_requests_api_key' => $this->token,
        ]);

        $this->agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);
        $this->agentExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->agent->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
        ]);

        $this->queue = CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Support',
        ]);
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'user_id' => $this->agent->id,
        ]);
    }

    private function dial(string $to, string $from = '1001'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/voice/route', [
            'To' => $to,
            'From' => $from,
            'CallSid' => 'dial-'.md5($to.$from),
            'Domain' => 'test.example.com',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);
    }

    public function test_agent_logs_in_by_dialing_feature_code(): void
    {
        Http::fake([
            'http://acd-worker:8084/queue/live' => Http::response([
                'waiting' => [],
                'agents' => [['userId' => (string) $this->agent->id, 'state' => 'LOGGED_OUT']],
            ], 200),
            'http://acd-worker:8084/*' => Http::response([], 204),
        ]);

        $response = $this->dial('*45'.$this->queue->id);

        $response->assertOk();
        $this->assertStringContainsString('logged in to queue Support', (string) $response->getContent());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/agents/state')
            && $request['state'] === 'available'
            && $request['userId'] === (string) $this->agent->id);
    }

    public function test_agent_logs_out_when_already_logged_in(): void
    {
        Http::fake([
            'http://acd-worker:8084/queue/live' => Http::response([
                'waiting' => [],
                'agents' => [['userId' => (string) $this->agent->id, 'state' => 'AVAILABLE']],
            ], 200),
            'http://acd-worker:8084/*' => Http::response([], 204),
        ]);

        $response = $this->dial('*45'.$this->queue->id);

        $response->assertOk();
        $this->assertStringContainsString('logged out of queue Support', (string) $response->getContent());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/agents/state')
            && $request['state'] === 'logged_out');
    }

    public function test_non_agent_is_rejected(): void
    {
        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $outsider = User::factory()->create(['organization_id' => $this->organization->id]);
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $outsider->id,
            'extension_number' => '1002',
        ]);

        $response = $this->dial('*45'.$this->queue->id, '1002');

        $response->assertOk();
        $this->assertStringContainsString('not an agent', (string) $response->getContent());
    }

    public function test_unknown_queue_is_rejected(): void
    {
        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $response = $this->dial('*45999');

        $response->assertOk();
        $this->assertStringContainsString('not found', (string) $response->getContent());
    }

    public function test_admin_can_toggle_agent_state_for_another_user(): void
    {
        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/call-queues/{$this->queue->id}/agents/me/state", [
            'state' => 'available',
            'user_id' => $this->agent->id,
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/agents/state')
            && $request['userId'] === (string) $this->agent->id
            && $request['state'] === 'available');
    }
}
