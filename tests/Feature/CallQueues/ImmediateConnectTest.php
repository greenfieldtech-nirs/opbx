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
use App\Models\QueueCall;
use App\Models\User;
use App\Services\CallQueue\ImmediateConnectService;
use App\Services\CallQueue\QueueAgentStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Immediate connect: agent availability interrupts the hold of waiting callers
 * via the Cloudonix REST application switch.
 */
class ImmediateConnectTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CallQueue $queue;

    private User $agent;

    private const CALL_ID = 'ic-call-001';
    private const SESSION_TOKEN = 'ic-session-token-001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://test.example.com',
            'domain_uuid' => 'domain-uuid-1',
            'domain_api_key' => 'domain-api-key-1',
        ]);

        $this->agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->agent->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
        ]);

        $this->queue = CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'immediate_connect' => true,
        ]);
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'user_id' => $this->agent->id,
        ]);
    }

    private function waitingCall(): QueueCall
    {
        return QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'session_token' => self::SESSION_TOKEN,
            'entered_at' => now(),
        ]);
    }

    public function test_agent_login_switches_waiting_caller_to_dial_application(): void
    {
        $this->waitingCall();

        Http::fake([
            'http://acd-worker:8084/queue/live' => Http::response([
                'waiting' => [['callId' => self::CALL_ID, 'position' => 1, 'waitedSeconds' => 20]],
                'agents' => [],
            ], 200),
            'http://acd-worker:8084/queue/poll' => Http::response([
                'action' => 'dial',
                'agents' => [['userId' => (string) $this->agent->id, 'extensionNumber' => '1001']],
            ], 200),
            'http://acd-worker:8084/*' => Http::response([], 204),
            '*' => Http::response([], 200),
        ]);

        app(QueueAgentStateService::class)->setState($this->queue, $this->agent->id, 'available');

        // Cloudonix REST: application switch for the waiting session.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sessions/'.self::SESSION_TOKEN.'/application')
            && str_contains((string) ($request['url'] ?? ''), 'queue-dial')
            && str_contains((string) ($request['url'] ?? ''), 'sig='));
    }

    public function test_call_entering_with_available_agent_switches_immediately(): void
    {
        // Regression: immediate connect only fired on login events, so a call
        // entering while agents were already available waited for the first
        // poll cycle (end of MOH) instead of connecting immediately.
        QueueCall::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('call_id', self::CALL_ID)->delete();

        Http::fake([
            'http://acd-worker:8084/queue/live' => Http::response([
                'waiting' => [['callId' => self::CALL_ID, 'position' => 1, 'waitedSeconds' => 0]],
                'agents' => [],
            ], 200),
            'http://acd-worker:8084/queue/poll' => Http::response([
                'action' => 'dial',
                'agents' => [['userId' => (string) $this->agent->id, 'extensionNumber' => '1001']],
            ], 200),
            'http://acd-worker:8084/*' => Http::response([], 204),
            '*' => Http::response([], 200),
        ]);

        $request = Request::create('/voice/route', 'POST', [
            'CallSid' => self::CALL_ID,
            'Session' => self::SESSION_TOKEN,
            'From' => '10000',
            'To' => '20001',
            '_organization_id' => $this->organization->id,
        ]);

        app(\App\Services\VoiceRouting\Strategies\QueueRoutingStrategy::class)
            ->route($request, new \App\Models\DidNumber, ['call_queue' => $this->queue]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sessions/'.self::SESSION_TOKEN.'/application'));
    }

    public function test_ghost_worker_entries_are_reaped_before_offering(): void
    {
        $this->waitingCall();

        Http::fake([
            // First live() shows a ghost ahead of our caller; after the reap
            // event, the second live() shows our caller at the head.
            'http://acd-worker:8084/queue/live' => Http::sequence()
                ->push(['waiting' => [
                    ['callId' => 'ghost-call', 'position' => 1, 'waitedSeconds' => 999],
                    ['callId' => self::CALL_ID, 'position' => 2, 'waitedSeconds' => 5],
                ], 'agents' => []])
                ->push(['waiting' => [
                    ['callId' => self::CALL_ID, 'position' => 1, 'waitedSeconds' => 5],
                ], 'agents' => []]),
            'http://acd-worker:8084/queue/poll' => Http::response([
                'action' => 'dial',
                'agents' => [['userId' => (string) $this->agent->id, 'extensionNumber' => '1001']],
            ], 200),
            'http://acd-worker:8084/*' => Http::response([], 204),
            '*' => Http::response([], 200),
        ]);

        app(ImmediateConnectService::class)->connectAvailableCallers($this->queue);

        // The ghost was told to leave the queue.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['callId'] === 'ghost-call'
            && $request['type'] === 'abandoned');

        // And the real caller was switched to the dial application.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sessions/'.self::SESSION_TOKEN.'/application'));
    }

    public function test_no_switch_when_toggle_disabled(): void
    {
        $this->queue->update(['immediate_connect' => false]);
        $this->waitingCall();

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        app(QueueAgentStateService::class)->setState($this->queue, $this->agent->id, 'available');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sessions/'.self::SESSION_TOKEN.'/application'));
    }

    public function test_no_switch_when_no_waiting_callers(): void
    {
        Http::fake([
            'http://acd-worker:8084/queue/live' => Http::response(['waiting' => [], 'agents' => []], 200),
            'http://acd-worker:8084/*' => Http::response([], 204),
        ]);

        app(QueueAgentStateService::class)->setState($this->queue, $this->agent->id, 'available');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cloudonix'));
    }

    public function test_signed_dial_endpoint_returns_dial_cxml_for_valid_signature(): void
    {
        $queueCall = $this->waitingCall();

        Http::fake([
            'http://acd-worker:8084/queue/poll' => Http::response([
                'action' => 'dial',
                'agents' => [['userId' => (string) $this->agent->id, 'extensionNumber' => '1001']],
            ], 200),
            'http://acd-worker:8084/*' => Http::response([], 204),
        ]);

        $service = app(ImmediateConnectService::class);
        $url = $service->signedDialUrl($this->queue, $queueCall);
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        $response = $this->call('GET', $path);

        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('1001', $content);
    }

    public function test_signed_dial_endpoint_rejects_invalid_signature(): void
    {
        $queueCall = $this->waitingCall();

        $response = $this->call('GET', '/api/callbacks/voice/queue-dial?sd=Zm9v&sig=invalid');

        $response->assertOk();
        $this->assertStringContainsString('<Hangup/>', (string) $response->getContent());
    }

    public function test_enqueue_captures_session_token(): void
    {
        $request = Request::create('/voice/route', 'POST', [
            'CallSid' => self::CALL_ID,
            'Session' => self::SESSION_TOKEN,
            'From' => '10000',
            'To' => '20001',
            '_organization_id' => $this->organization->id,
        ]);

        app(\App\Services\VoiceRouting\Strategies\QueueRoutingStrategy::class)
            ->route($request, new \App\Models\DidNumber, ['call_queue' => $this->queue]);

        $this->assertDatabaseHas('queue_calls', [
            'call_queue_id' => $this->queue->id,
            'call_id' => self::CALL_ID,
            'session_token' => self::SESSION_TOKEN,
        ]);
    }
}
