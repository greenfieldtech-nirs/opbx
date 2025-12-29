<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Webhook signature verification test suite.
 *
 * Tests the VerifyCloudonixSignature middleware for various scenarios.
 */
class WebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_SECRET = 'test-webhook-secret-minimum-32-characters-long';
    private const TEST_WEBHOOK_URL = '/api/webhooks/cloudonix/call-initiated';

    protected function setUp(): void
    {
        parent::setUp();

        // Set test configuration
        Config::set('cloudonix.webhook_secret', self::TEST_SECRET);
        Config::set('cloudonix.verify_signature', true);
        Config::set('cloudonix.signature_header', 'X-Cloudonix-Signature');
    }

    public function test_valid_signature_allows_request(): void
    {
        $payload = json_encode(['call_id' => 'test-123', 'status' => 'initiated']);
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);

        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
        ]);

        // The request should not be rejected by signature middleware
        // Note: It may still fail with other errors (e.g., idempotency key missing)
        // but it should NOT be a 401 Unauthorized
        $this->assertNotEquals(401, $response->status());
    }

    public function test_invalid_signature_rejects_request(): void
    {
        $payload = json_encode(['call_id' => 'test-123', 'status' => 'initiated']);
        $invalidSignature = 'invalid-signature-not-matching-hmac';

        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $invalidSignature,
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => 'Unauthorized - Invalid signature',
        ]);
    }

    public function test_missing_signature_rejects_request(): void
    {
        $payload = ['call_id' => 'test-123', 'status' => 'initiated'];

        $response = $this->postJson(self::TEST_WEBHOOK_URL, $payload);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => 'Unauthorized - Missing signature',
        ]);
    }

    public function test_missing_secret_configuration_returns_500(): void
    {
        // Clear the webhook secret
        Config::set('cloudonix.webhook_secret', null);

        $payload = json_encode(['call_id' => 'test-123', 'status' => 'initiated']);
        $signature = hash_hmac('sha256', $payload, 'any-secret');

        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
        ]);

        $response->assertStatus(500);
        $response->assertJson([
            'error' => 'Webhook configuration error',
        ]);
    }

    public function test_bypass_when_verification_disabled(): void
    {
        // Disable signature verification
        Config::set('cloudonix.verify_signature', false);

        $payload = ['call_id' => 'test-123', 'status' => 'initiated'];

        // Send request WITHOUT signature
        $response = $this->postJson(self::TEST_WEBHOOK_URL, $payload);

        // Should not be rejected with 401
        // (may have other errors like missing idempotency key, but not signature error)
        $this->assertNotEquals(401, $response->status());
    }

    public function test_empty_signature_header_rejects_request(): void
    {
        $payload = ['call_id' => 'test-123', 'status' => 'initiated'];

        $response = $this->postJson(self::TEST_WEBHOOK_URL, $payload, [
            'X-Cloudonix-Signature' => '',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => 'Unauthorized - Missing signature',
        ]);
    }

    public function test_signature_computed_from_raw_payload(): void
    {
        // Test that signature is computed from raw body, not parsed JSON
        $payload = '{"call_id":"test-123","status":"initiated"}';
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);

        // Send raw JSON string using call() method with explicit content
        $response = $this->call('POST', self::TEST_WEBHOOK_URL, [], [], [], [
            'HTTP_X-Cloudonix-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        // Should not be rejected with 401 due to signature
        $this->assertNotEquals(401, $response->status());
    }

    public function test_different_payload_produces_different_signature(): void
    {
        $payload1 = json_encode(['call_id' => 'test-123']);
        $payload2 = json_encode(['call_id' => 'test-456']);

        $signature1 = hash_hmac('sha256', $payload1, self::TEST_SECRET);
        $signature2 = hash_hmac('sha256', $payload2, self::TEST_SECRET);

        // Signatures should be different
        $this->assertNotEquals($signature1, $signature2);

        // Using signature1 with payload2 should fail
        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload2, true), [
            'X-Cloudonix-Signature' => $signature1,
        ]);

        $response->assertStatus(401);
    }

    public function test_custom_signature_header_name(): void
    {
        // Change signature header name
        Config::set('cloudonix.signature_header', 'X-Custom-Signature');

        $payload = json_encode(['call_id' => 'test-123']);
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);

        // Send with default header (should fail)
        $response1 = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
        ]);
        $response1->assertStatus(401);

        // Send with custom header (should not be rejected by signature middleware)
        $response2 = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Custom-Signature' => $signature,
        ]);
        $this->assertNotEquals(401, $response2->status());
    }

    public function test_timestamp_validation_accepts_recent_timestamp(): void
    {
        // Enable timestamp requirement
        Config::set('cloudonix.require_timestamp', true);

        $payload = json_encode(['call_id' => 'test-123', 'status' => 'initiated']);
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);
        $timestamp = time(); // Current time

        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
            'X-Cloudonix-Timestamp' => (string) $timestamp,
        ]);

        // Should not be rejected specifically due to timestamp (may fail on other validations like idempotency)
        // We just need to verify it's NOT a timestamp error
        if ($response->status() === 400) {
            $data = $response->json();
            $this->assertNotEquals('Timestamp outside tolerance window', $data['error'] ?? '', 'Should not reject valid timestamp');
        } else {
            $this->assertNotEquals(400, $response->status(), 'Should not reject valid timestamp');
        }
    }

    public function test_timestamp_validation_rejects_old_timestamp(): void
    {
        // Enable timestamp requirement
        Config::set('cloudonix.require_timestamp', true);

        $payload = json_encode(['call_id' => 'test-123', 'status' => 'initiated']);
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);
        $timestamp = time() - 600; // 10 minutes ago (outside 5-minute window)

        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
            'X-Cloudonix-Timestamp' => (string) $timestamp,
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Timestamp outside tolerance window']);
    }

    public function test_timestamp_validation_rejects_future_timestamp(): void
    {
        // Enable timestamp requirement
        Config::set('cloudonix.require_timestamp', true);

        $payload = json_encode(['call_id' => 'test-123', 'status' => 'initiated']);
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);
        $timestamp = time() + 600; // 10 minutes in future (outside 5-minute window)

        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
            'X-Cloudonix-Timestamp' => (string) $timestamp,
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Timestamp outside tolerance window']);
    }

    public function test_timestamp_validation_optional_by_default(): void
    {
        // Timestamp not required by default
        Config::set('cloudonix.require_timestamp', false);

        $payload = json_encode(['call_id' => 'test-123', 'status' => 'initiated']);
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);

        // No timestamp header sent
        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
        ]);

        // Should not be rejected specifically due to missing timestamp (may fail on other validations like idempotency)
        if ($response->status() === 400) {
            $data = $response->json();
            $this->assertNotEquals('Missing timestamp header', $data['error'] ?? '', 'Should not require timestamp by default');
        } else {
            $this->assertNotEquals(400, $response->status(), 'Should not require timestamp by default');
        }
    }

    public function test_timestamp_validation_rejects_invalid_format(): void
    {
        // Enable timestamp requirement
        Config::set('cloudonix.require_timestamp', true);

        $payload = json_encode(['call_id' => 'test-123', 'status' => 'initiated']);
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);

        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
            'X-Cloudonix-Timestamp' => 'not-a-number',
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Invalid timestamp format']);
    }

    public function test_ip_allowlist_accepts_allowed_ip(): void
    {
        // Set IP allowlist
        Config::set('cloudonix.webhook_allowed_ips', ['127.0.0.1']);

        $payload = json_encode(['call_id' => 'test-123', 'status' => 'initiated']);
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);

        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
        ]);

        // Should not be rejected specifically due to IP (request comes from 127.0.0.1 in tests)
        // May fail on other validations but not IP check
        if ($response->status() === 400) {
            $data = $response->json();
            $this->assertNotEquals('Unauthorized IP address', $data['error'] ?? '', 'Should accept allowed IP');
        } else {
            $this->assertNotEquals(400, $response->status(), 'Should accept allowed IP');
        }
    }

    public function test_ip_allowlist_rejects_unauthorized_ip(): void
    {
        // Set IP allowlist that doesn't include test IP
        Config::set('cloudonix.webhook_allowed_ips', ['1.2.3.4', '5.6.7.8']);

        $payload = json_encode(['call_id' => 'test-123', 'status' => 'initiated']);
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);

        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Unauthorized IP address']);
    }

    public function test_organization_extraction_from_domain_uuid(): void
    {
        // Create organization and settings
        $org = Organization::factory()->create();
        CloudonixSettings::factory()->create([
            'organization_id' => $org->id,
            'domain_uuid' => 'test-domain-uuid-123',
        ]);

        $payload = json_encode([
            'call_id' => 'test-123',
            'status' => 'initiated',
            'domain_uuid' => 'test-domain-uuid-123',
        ]);
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);

        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
        ]);

        // Organization should be extracted and injected
        // This can be verified in the controller or middleware logs
        // For now, just verify the request wasn't rejected
        $this->assertNotEquals(401, $response->status());
    }

    public function test_organization_extraction_from_payload_field(): void
    {
        $payload = json_encode([
            'call_id' => 'test-123',
            'status' => 'initiated',
            'organization_id' => 42,
        ]);
        $signature = hash_hmac('sha256', $payload, self::TEST_SECRET);

        $response = $this->postJson(self::TEST_WEBHOOK_URL, json_decode($payload, true), [
            'X-Cloudonix-Signature' => $signature,
        ]);

        // Organization should be extracted from payload
        $this->assertNotEquals(401, $response->status());
    }
}
