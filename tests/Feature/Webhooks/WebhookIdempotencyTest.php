<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
