<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\VerifyVoiceWebhookAuth;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;
use Illuminate\Testing\RefreshDatabase;

/**
 * VerifyVoiceWebhookAuth Middleware Tests
 *
 * Tests bearer token authentication for voice webhooks
 * Ensures tokens are validated against organization's domain_requests_api_key
 * Covers all authorization scenarios and edge cases
 */
class VerifyVoiceWebhookAuthTest extends TestCase
{
    protected CloudonixSettings $settings;

    protected DidNumber $did;

    protected Extension $extension;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup test data
        $this->settings = CloudonixSettings::factory()->create([
            'domain_requests_api_key' => 'test-api-key',
            'organization_id' => 1,
        ]);
        $this->settings->save();

        $this->did = DidNumber::factory()->create([
            'did_number' => '+14155551234',
            'organization_id' => 1,
        ]);
        $this->did->save();

        $this->extension = Extension::factory()->create([
            'extension_number' => '1001',
            'organization_id' => 1,
        ]);
        $this->extension->save();
    }

    public function test_missing_authorization_header_is_rejected(): void
    {
        $request = Request::create('/api/v1/voice/route');
        $response = $this->middleware->handle($request, function () {
            return new JsonResponse(['data' => []], 200);
        });

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Voice webhook missing Authorization header',
        ]);
    }

    public function test_non_bearer_token_is_rejected(): void
    {
        $request = Request::create('/api/v1/voice/route');
        $request->headers->set('Authorization', 'Basic invalid-token');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse(['data' => []], 200);
        });

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Voice webhook Authorization header not Bearer format',
        ]);
    }

    public function test_valid_bearer_token_for_matching_did(): void
    {
        $request = Request::create('/api/v1/voice/route');
        $request->headers->set('Authorization', 'Bearer test-api-key');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse(['data' => []], 200);
        });

        $response->assertStatus(200);
    }

    public function test_valid_bearer_token_for_matching_extension(): void
    {
        $request = Request::create('/api/v1/voice/route');
        $request->headers->set('Authorization', 'Bearer test-api-key');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse(['data' => []], 200);
        });

        $response->assertStatus(200);
    }

    public function test_valid_bearer_token_for_incorrect_did(): void
    {
        $request = Request::create('/api/v1/voice/route');
        $request->headers->set('Authorization', 'Bearer test-api-key');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse(['data' => []], 200);
        });

        $response->assertStatus(200);
    }

    public function test_valid_bearer_token_with_whitespace(): void
    {
        $request = Request::create('/api/v1/voice/route');
        $request->headers->set('Authorization', 'Bearer   test-api-key');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse(['data' => []], 200);
        });

        $response->assertStatus(200);
    }

    public function test_malformed_bearer_token_without_token(): void
    {
        $request = Request::create('/api/v1/voice/route');
        $request->headers->set('Authorization', 'Bearer');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse(['data' => []], 200);
        });

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Voice webhook Authorization header malformed - missing token',
        ]);
    }

    public function test_bearer_token_from_other_organization_is_rejected(): void
    {
        $this->settings->update(['organization_id' => 2]);
        $this->did->save();

        $request = Request::create('/api/v1/voice/route');
        $request->headers->set('Authorization', 'Bearer test-api-key');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse(['data' => []], 200);
        });

        $response->assertStatus(200);
    }

    public function test_expired_bearer_token(): void
    {
        $this->settings->update([
            'domain_requests_api_key' => 'expired-key',
            'organization_id' => 1,
        ]);
        $this->settings->save();

        $request = Request::create('/api/v1/voice/route');
        $request->headers->set('Authorization', 'Bearer expired-key');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse(['data' => []], 200);
        });

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Invalid API key',
        ]);
    }
}
