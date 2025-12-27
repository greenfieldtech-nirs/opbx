<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
