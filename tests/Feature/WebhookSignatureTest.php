<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Webhook authentication test suite.
 *
 * Exercises the VerifyCloudonixSignature middleware (alias `webhook.signature`).
 *
 * The current authentication contract is:
 *   1. The payload MUST carry a domain (top-level `domain`, or CDR
 *      `owner.domain.name` / `owner.domain.uuid`). Missing → 400.
 *   2. The domain MUST resolve to a CloudonixSettings record via `domain_name`
 *      or `domain_uuid`. Unknown → 401.
 *   3. If that organization has a `domain_requests_api_key`, a matching Bearer
 *      token is required. Missing/invalid → 401. If no key is configured, the
 *      request is allowed without a token.
 *
 * The legacy HMAC-signature / timestamp / IP-allowlist behaviour tested here
 * previously no longer exists in the middleware and has been removed from the
 * suite accordingly.
 */
class WebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_WEBHOOK_URL = '/api/webhooks/cloudonix/call-initiated';

    private const DOMAIN = 'signature-test.cloudonix.net';

    private const API_KEY = 'signature-test-requests-api-key-1234567890';

    private Organization $organization;

    private CloudonixSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->settings = CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'domain_name' => self::DOMAIN,
            'domain_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'domain_requests_api_key' => self::API_KEY,
        ]);
    }

    /**
     * A minimal payload that satisfies the CallInitiatedRequest validation rules
     * so requests that pass the middleware reach the controller (200).
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'domain' => self::DOMAIN,
            'call_id' => 'test-123',
            'from' => '+12025551234',
            'to' => '+13105559999',
            'did' => '+13105559999',
            'status' => 'initiated',
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(?string $token = self::API_KEY): array
    {
        return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
    }

    public function test_missing_domain_returns_400(): void
    {
        // No domain anywhere in the payload.
        $response = $this->postJson(self::TEST_WEBHOOK_URL, [
            'call_id' => 'test-123',
            'status' => 'initiated',
        ], $this->authHeaders());

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Bad Request - Missing domain parameter']);
    }

    public function test_unknown_domain_returns_401(): void
    {
        $response = $this->postJson(self::TEST_WEBHOOK_URL, $this->validPayload([
            'domain' => 'not-a-known-domain.cloudonix.net',
        ]), $this->authHeaders());

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized - Unknown domain']);
    }

    public function test_missing_bearer_token_when_auth_configured_returns_401(): void
    {
        // Domain resolves, org has an api key, but no Authorization header sent.
        $response = $this->postJson(self::TEST_WEBHOOK_URL, $this->validPayload(), $this->authHeaders(null));

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized - Bearer token required']);
    }

    public function test_invalid_bearer_token_returns_401(): void
    {
        $response = $this->postJson(
            self::TEST_WEBHOOK_URL,
            $this->validPayload(),
            $this->authHeaders('completely-wrong-token')
        );

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized - Invalid Bearer token']);
    }

    public function test_valid_bearer_token_reaches_controller(): void
    {
        $response = $this->postJson(self::TEST_WEBHOOK_URL, $this->validPayload(), $this->authHeaders());

        // Passed auth → controller acknowledges receipt.
        $response->assertStatus(200);
        $response->assertJson(['status' => 'accepted']);
    }

    public function test_no_auth_configured_allows_request_without_token(): void
    {
        // Organization without a domain_requests_api_key: token is optional.
        $org = Organization::factory()->create();
        CloudonixSettings::factory()->create([
            'organization_id' => $org->id,
            'domain_name' => 'no-auth.cloudonix.net',
            'domain_requests_api_key' => null,
        ]);

        $response = $this->postJson(self::TEST_WEBHOOK_URL, $this->validPayload([
            'domain' => 'no-auth.cloudonix.net',
        ]), $this->authHeaders(null));

        $response->assertStatus(200);
        $response->assertJson(['status' => 'accepted']);
    }

    public function test_domain_resolved_by_uuid_top_level(): void
    {
        // Domain provided as the settings' UUID resolves via domain_uuid.
        $response = $this->postJson(self::TEST_WEBHOOK_URL, $this->validPayload([
            'domain' => $this->settings->domain_uuid,
        ]), $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJson(['status' => 'accepted']);
    }

    public function test_domain_resolved_from_cdr_owner_domain_name(): void
    {
        // CDR-style payload carries the domain under owner.domain.name.
        $response = $this->postJson(self::TEST_WEBHOOK_URL, [
            'call_id' => 'test-123',
            'from' => '+12025551234',
            'to' => '+13105559999',
            'did' => '+13105559999',
            'status' => 'initiated',
            'owner' => ['domain' => ['name' => self::DOMAIN]],
        ], $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJson(['status' => 'accepted']);
    }

    public function test_wrong_token_is_not_accepted_for_valid_domain(): void
    {
        // Regression guard: a valid domain must not bypass token validation.
        $response = $this->postJson(
            self::TEST_WEBHOOK_URL,
            $this->validPayload(),
            $this->authHeaders(substr(self::API_KEY, 0, -1)) // one char off
        );

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized - Invalid Bearer token']);
    }
}
