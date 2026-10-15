<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\CallDetailRecord;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'idempotency-test.cloudonix.net';

    private const API_KEY = 'idempotency-test-requests-api-key-1234567890';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'domain_name' => self::DOMAIN,
            'domain_requests_api_key' => self::API_KEY,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.self::API_KEY];
    }

    public function test_webhook_processes_only_once_with_same_payload(): void
    {
        DidNumber::create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+1234567890',
            'friendly_name' => 'Test DID',
            'routing_type' => 'extension',
            'routing_config' => [],
            'status' => 'active',
        ]);

        $payload = [
            'domain' => self::DOMAIN,
            'call_id' => 'test-call-'.uniqid(),
            'from' => '+9876543210',
            'to' => '+1234567890',
            'did' => '+1234567890',
            'status' => 'initiated',
        ];

        // First request
        $response1 = $this->postJson('/api/webhooks/cloudonix/call-initiated', $payload, $this->authHeaders());
        $response1->assertStatus(200);

        // Second request with same payload (should be idempotent)
        $response2 = $this->postJson('/api/webhooks/cloudonix/call-initiated', $payload, $this->authHeaders());
        $response2->assertStatus(200);

        // Both should return the same CXML response
        $this->assertEquals($response1->getContent(), $response2->getContent());
    }

    public function test_webhook_with_explicit_idempotency_key(): void
    {
        DidNumber::create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+1234567890',
            'routing_type' => 'extension',
            'routing_config' => [],
            'status' => 'active',
        ]);

        $idempotencyKey = 'test-key-'.uniqid();

        $payload = [
            'domain' => self::DOMAIN,
            'call_id' => 'test-call-1',
            'from' => '+9876543210',
            'to' => '+1234567890',
            'status' => 'initiated',
        ];

        $headers = $this->authHeaders() + ['X-Idempotency-Key' => $idempotencyKey];

        // First request
        $response1 = $this->postJson('/api/webhooks/cloudonix/call-status', $payload, $headers);
        $response1->assertStatus(200);

        // Second request with same idempotency key
        $response2 = $this->postJson('/api/webhooks/cloudonix/call-status', $payload, $headers);
        $response2->assertStatus(200);
    }

    public function test_different_webhooks_process_independently(): void
    {
        DidNumber::create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+1234567890',
            'routing_type' => 'extension',
            'routing_config' => [],
            'status' => 'active',
        ]);

        $payload1 = [
            'domain' => self::DOMAIN,
            'call_id' => 'call-1',
            'from' => '+9876543210',
            'to' => '+1234567890',
            'did' => '+1234567890',
        ];

        $payload2 = [
            'domain' => self::DOMAIN,
            'call_id' => 'call-2',
            'from' => '+9876543210',
            'to' => '+1234567890',
            'did' => '+1234567890',
        ];

        $response1 = $this->postJson('/api/webhooks/cloudonix/call-initiated', $payload1, $this->authHeaders());
        $response2 = $this->postJson('/api/webhooks/cloudonix/call-initiated', $payload2, $this->authHeaders());

        // Both distinct payloads are accepted independently (not deduped against
        // each other). The controller returns a fixed acknowledgement, so the
        // response bodies are identical by design — the intent here is that
        // neither request is rejected as a duplicate of the other.
        $response1->assertStatus(200)->assertJson(['status' => 'accepted']);
        $response2->assertStatus(200)->assertJson(['status' => 'accepted']);
    }

    /** Registers a probe route behind the idempotency middleware; $handler decides the response. */
    private function probe(callable $handler): void
    {
        Route::post('/_idem-probe', $handler)->middleware('webhook.idempotency');
    }

    public function test_a_failed_first_attempt_is_not_cached_and_the_retry_is_processed(): void
    {
        $runs = 0;
        $this->probe(function () use (&$runs) {
            return ++$runs === 1 ? response()->json(['error' => 'boom'], 500) : response()->json(['ok' => true]);
        });
        $headers = ['X-Idempotency-Key' => 'retry-after-500'];

        $this->postJson('/_idem-probe', [], $headers)->assertStatus(500);
        $this->postJson('/_idem-probe', [], $headers)->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(2, $runs);
    }

    public function test_a_duplicate_while_the_claim_is_held_does_not_run_the_handler(): void
    {
        $runs = 0;
        $this->probe(function () use (&$runs) {
            $runs++;

            return response()->json(['ok' => true]);
        });
        Cache::put('idem:webhook:held-key:lock', 1, 60);

        $this->postJson('/_idem-probe', [], ['X-Idempotency-Key' => 'held-key'])->assertStatus(409);

        $this->assertSame(0, $runs);
    }

    public function test_json_responses_replay_as_json(): void
    {
        $this->probe(fn () => response()->json(['ok' => true]));
        $headers = ['X-Idempotency-Key' => 'json-replay'];

        $this->postJson('/_idem-probe', [], $headers)->assertOk();
        $replay = $this->postJson('/_idem-probe', [], $headers)->assertOk();

        $this->assertStringContainsString('application/json', $replay->headers->get('Content-Type'));
        $replay->assertJson(['ok' => true]);
    }

    public function test_xml_responses_replay_as_xml_and_the_claim_is_released(): void
    {
        $this->probe(fn () => response('<Response/>', 200)->header('Content-Type', 'application/xml'));
        $headers = ['X-Idempotency-Key' => 'xml-replay'];

        $this->postJson('/_idem-probe', [], $headers)->assertOk();
        $replay = $this->postJson('/_idem-probe', [], $headers)->assertOk();

        $this->assertStringContainsString('application/xml', $replay->headers->get('Content-Type'));
        $this->assertFalse(Cache::has('idem:webhook:xml-replay:lock'));
    }

    public function test_a_duplicate_cdr_with_a_different_idempotency_key_leaves_one_row(): void
    {
        $payload = [
            'timestamp' => time(),
            'domain' => self::DOMAIN,
            'from' => '+15550001111',
            'to' => '+15550002222',
            'disposition' => 'ANSWER',
            'duration' => 30,
            'billsec' => 25,
            'call_id' => 'dup-cdr-1',
        ];

        $this->postJson('/api/webhooks/cloudonix/cdr', $payload, $this->authHeaders() + ['X-Idempotency-Key' => 'a'])->assertOk();
        $this->postJson('/api/webhooks/cloudonix/cdr', $payload, $this->authHeaders() + ['X-Idempotency-Key' => 'b'])->assertOk();

        $this->assertSame(1, CallDetailRecord::withoutGlobalScopes()->where('call_id', 'dup-cdr-1')->count());
    }
}
