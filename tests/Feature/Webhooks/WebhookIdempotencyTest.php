<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\DidNumber;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_processes_only_once_with_same_payload(): void
    {
        $organization = Organization::factory()->create();

        $didNumber = DidNumber::create([
            'organization_id' => $organization->id,
            'phone_number' => '+1234567890',
            'friendly_name' => 'Test DID',
            'routing_type' => 'voicemail',
            'routing_config' => [],
            'status' => 'active',
        ]);

        $payload = [
            'CallSid' => 'test-call-' . uniqid(),
            'From' => '+9876543210',
            'To' => '+1234567890',
            'CallStatus' => 'initiated',
        ];

        // First request
        $response1 = $this->postJson('/api/webhooks/cloudonix/call-initiated', $payload);
        $response1->assertStatus(200);

        // Second request with same payload (should be idempotent)
        $response2 = $this->postJson('/api/webhooks/cloudonix/call-initiated', $payload);
        $response2->assertStatus(200);

        // Both should return the same CXML response
        $this->assertEquals($response1->getContent(), $response2->getContent());
    }

    public function test_webhook_with_explicit_idempotency_key(): void
    {
        $organization = Organization::factory()->create();

        DidNumber::create([
            'organization_id' => $organization->id,
            'phone_number' => '+1234567890',
            'routing_type' => 'voicemail',
            'routing_config' => [],
            'status' => 'active',
        ]);

        $idempotencyKey = 'test-key-' . uniqid();

        $payload = [
            'call_id' => 'test-call-1',
            'from' => '+9876543210',
            'to' => '+1234567890',
            'status' => 'initiated',
        ];

        // First request
        $response1 = $this->postJson(
            '/api/webhooks/cloudonix/call-status',
            $payload,
            ['X-Idempotency-Key' => $idempotencyKey]
        );
        $response1->assertStatus(200);

        // Second request with same idempotency key
        $response2 = $this->postJson(
            '/api/webhooks/cloudonix/call-status',
            $payload,
            ['X-Idempotency-Key' => $idempotencyKey]
        );
        $response2->assertStatus(200);
    }

    public function test_different_webhooks_process_independently(): void
    {
        $organization = Organization::factory()->create();

        DidNumber::create([
            'organization_id' => $organization->id,
            'phone_number' => '+1234567890',
            'routing_type' => 'voicemail',
            'routing_config' => [],
            'status' => 'active',
        ]);

        $payload1 = [
            'CallSid' => 'call-1',
            'From' => '+9876543210',
            'To' => '+1234567890',
        ];

        $payload2 = [
            'CallSid' => 'call-2',
            'From' => '+9876543210',
            'To' => '+1234567890',
        ];

        $response1 = $this->postJson('/api/webhooks/cloudonix/call-initiated', $payload1);
        $response2 = $this->postJson('/api/webhooks/cloudonix/call-initiated', $payload2);

        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Both should process successfully
        $this->assertNotEquals($response1->getContent(), $response2->getContent());
    }
}
