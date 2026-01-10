<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\VerifyVoiceWebhookAuth;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VerifyVoiceWebhookAuth Middleware Tests
 *
 * Tests bearer token authentication for voice webhooks
 * Ensures tokens are validated against organization's domain_requests_api_key
 * Covers all authorization scenarios and edge cases
 */
class VerifyVoiceWebhookAuthTest extends TestCase
{
    use RefreshDatabase;

    private VerifyVoiceWebhookAuth $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        // Create middleware instance (will use real models)
        $this->middleware = new VerifyVoiceWebhookAuth();
    }

    private function createCloudonixSettingsMock()
    {
        $mock = Mockery::mock(CloudonixSettings::class);

        // Mock the query builder chain for CloudonixSettings::where()->first()
        $queryBuilder = Mockery::mock();
        $queryBuilder->shouldReceive('first')->andReturn(null); // Default to no settings

        $mock->shouldReceive('where')
            ->withAnyArgs()
            ->andReturn($queryBuilder);

        return $mock;
    }

    private function createDidNumberMock()
    {
        $mock = Mockery::mock(DidNumber::class);

        // Mock the complex query builder chain for DidNumber lookups
        $queryBuilder = Mockery::mock();
        $queryBuilder->shouldReceive('where')
            ->withAnyArgs()
            ->andReturnSelf();
        $queryBuilder->shouldReceive('first')
            ->andReturn(null); // Default to no DID found

        $mock->shouldReceive('withoutGlobalScope')
            ->withAnyArgs()
            ->andReturn($queryBuilder);

        return $mock;
    }

    private function createExtensionMock()
    {
        $mock = Mockery::mock(Extension::class);

        // Mock the complex query builder chain for Extension lookups
        $queryBuilder = Mockery::mock();
        $queryBuilder->shouldReceive('where')
            ->withAnyArgs()
            ->andReturnSelf();
        $queryBuilder->shouldReceive('whereIn')
            ->withAnyArgs()
            ->andReturnSelf();
        $queryBuilder->shouldReceive('first')
            ->andReturn(null); // Default to no extension found

        $mock->shouldReceive('withoutGlobalScope')
            ->withAnyArgs()
            ->andReturn($queryBuilder);

        return $mock;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_missing_authorization_header_is_rejected(): void
    {
        $request = Request::create('/api/voice/route');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Unauthorized', $response->getContent());
        $this->assertStringContainsString('Content-Type', 'application/xml');
    }

    public function test_non_bearer_token_is_rejected(): void
    {
        $request = Request::create('/api/voice/route');
        $request->headers->set('Authorization', 'Basic invalid-token');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Unauthorized', $response->getContent());
        $this->assertStringContainsString('Content-Type', 'application/xml');
    }

    public function test_malformed_bearer_token_without_token(): void
    {
        $request = Request::create('/api/voice/route');
        $request->headers->set('Authorization', 'Bearer');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Unauthorized', $response->getContent());
        $this->assertStringContainsString('Content-Type', 'application/xml');
    }

    public function test_bearer_token_with_whitespace_is_accepted(): void
    {
        // Create real test data
        $settings = CloudonixSettings::factory()->create([
            'domain_requests_api_key' => 'test-api-key',
            'organization_id' => 1,
        ]);

        $did = DidNumber::factory()->create([
            'phone_number' => '+14155551234',
            'organization_id' => 1,
            'status' => 'active',
        ]);

        $request = Request::create('/api/voice/route', 'POST', [], [], [], [], json_encode(['to' => '+14155551234']));
        $request->headers->set('Authorization', 'Bearer   test-api-key   ');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('1', $request->get('_organization_id'));
    }

    public function test_valid_bearer_token_for_matching_did(): void
    {
        // Create real test data
        $settings = CloudonixSettings::factory()->create([
            'domain_requests_api_key' => 'test-api-key',
            'organization_id' => 1,
        ]);

        $did = DidNumber::factory()->create([
            'phone_number' => '+14155551234',
            'organization_id' => 1,
            'status' => 'active',
        ]);

        $request = Request::create('/api/voice/route', 'POST', [], [], [], [], json_encode(['to' => '+14155551234']));
        $request->headers->set('Authorization', 'Bearer test-api-key');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('1', $request->get('_organization_id'));
    }

    public function test_valid_bearer_token_for_matching_extension(): void
    {
        // Create real test data
        $settings = CloudonixSettings::factory()->create([
            'domain_requests_api_key' => 'test-api-key',
            'organization_id' => 1,
        ]);

        $extension = Extension::factory()->create([
            'extension_number' => '1001',
            'organization_id' => 1,
            'type' => 'user',
            'status' => 'active',
        ]);

        $request = Request::create('/api/voice/route', 'POST', [], [], [], [], json_encode(['from' => '1001', 'to' => '+14155551234']));
        $request->headers->set('Authorization', 'Bearer test-api-key');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('1', $request->get('_organization_id'));
    }

    public function test_invalid_bearer_token_for_matching_did(): void
    {
        // Mock DID lookup
        $didMockResult = Mockery::mock(DidNumber::class)->makePartial()->shouldIgnoreMissing();
        $didMockResult->organization_id = 1;

        $didQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
        $didQueryBuilderMock->shouldReceive('where')
            ->with('phone_number', '+14155551234')
            ->andReturnSelf();
        $didQueryBuilderMock->shouldReceive('where')
            ->with('status', 'active')
            ->andReturnSelf();
        $didQueryBuilderMock->shouldReceive('first')
            ->andReturn($didMockResult);

        $this->didNumberMock->shouldReceive('withoutGlobalScope')
            ->with(\App\Scopes\OrganizationScope::class)
            ->andReturn($didQueryBuilderMock);

        // Mock CloudonixSettings lookup
        $settingsMockResult = Mockery::mock(CloudonixSettings::class)->makePartial()->shouldIgnoreMissing();
        $settingsMockResult->domain_requests_api_key = 'correct-api-key';

        $settingsQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
        $settingsQueryBuilderMock->shouldReceive('first')
            ->andReturn($settingsMockResult);

        $this->cloudonixSettingsMock->shouldReceive('where')
            ->with('organization_id', 1)
            ->andReturn($settingsQueryBuilderMock);

        // Mock Extension lookup to return null (not found)
        $extensionQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
        $extensionQueryBuilderMock->shouldReceive('where')
            ->with('extension_number', '+14155551234')
            ->andReturnSelf();
        $extensionQueryBuilderMock->shouldReceive('whereIn')
            ->with('type', ['user', 'ai_assistant'])
            ->andReturnSelf();
        $extensionQueryBuilderMock->shouldReceive('where')
            ->with('status', 'active')
            ->andReturnSelf();
        $extensionQueryBuilderMock->shouldReceive('first')
            ->andReturn(null);

        $this->extensionMock->shouldReceive('withoutGlobalScope')
            ->with(\App\Scopes\OrganizationScope::class)
            ->andReturn($extensionQueryBuilderMock);

        $request = Request::create('/api/voice/route', 'POST', [], [], [], [], json_encode(['to' => '+14155551234']));
        $request->headers->set('Authorization', 'Bearer wrong-api-key');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Unauthorized. Authentication failed.', $response->getContent());
        $this->assertStringContainsString('<Response>', $response->getContent());
        $this->assertEquals('application/xml', $response->headers->get('Content-Type'));
    }

    public function test_timing_safe_token_comparison(): void
    {
        // Test with different length tokens to ensure timing safety
        $testCases = [
            'test-api-ke' => false, // shorter
            'test-api-keyX' => false, // longer
            'test-api-key' => true, // exact match
            'wrong-api-key' => false, // same length, different content
        ];

        foreach ($testCases as $token => $shouldPass) {
            // Create fresh mocks for each iteration
            $cloudonixSettingsMock = Mockery::mock(CloudonixSettings::class)->makePartial()->shouldIgnoreMissing();
            $didNumberMock = Mockery::mock(DidNumber::class)->makePartial()->shouldIgnoreMissing();
            $extensionMock = Mockery::mock(Extension::class)->makePartial()->shouldIgnoreMissing();

            // Mock DID lookup
            $didMockResult = Mockery::mock(DidNumber::class)->makePartial()->shouldIgnoreMissing();
            $didMockResult->organization_id = 1;

            $didQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
            $didQueryBuilderMock->shouldReceive('where')
                ->with('phone_number', '+14155551234')
                ->andReturnSelf();
            $didQueryBuilderMock->shouldReceive('where')
                ->with('status', 'active')
                ->andReturnSelf();
            $didQueryBuilderMock->shouldReceive('first')
                ->andReturn($didMockResult);

            $didNumberMock->shouldReceive('withoutGlobalScope')
                ->with(\App\Scopes\OrganizationScope::class)
                ->andReturn($didQueryBuilderMock);

            // Mock CloudonixSettings lookup
            $settingsMockResult = Mockery::mock(CloudonixSettings::class)->makePartial()->shouldIgnoreMissing();
            $settingsMockResult->domain_requests_api_key = 'test-api-key';

            $settingsQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
            $settingsQueryBuilderMock->shouldReceive('first')
                ->andReturn($settingsMockResult);

            $cloudonixSettingsMock->shouldReceive('where')
                ->with('organization_id', 1)
                ->andReturn($settingsQueryBuilderMock);

            // Mock Extension lookup to return null (not found)
            $extensionQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
            $extensionQueryBuilderMock->shouldReceive('where')
                ->with('extension_number', '+14155551234')
                ->andReturnSelf();
            $extensionQueryBuilderMock->shouldReceive('whereIn')
                ->with('type', ['user', 'ai_assistant'])
                ->andReturnSelf();
            $extensionQueryBuilderMock->shouldReceive('where')
                ->with('status', 'active')
                ->andReturnSelf();
            $extensionQueryBuilderMock->shouldReceive('first')
                ->andReturn(null);

            $extensionMock->shouldReceive('withoutGlobalScope')
                ->with(\App\Scopes\OrganizationScope::class)
                ->andReturn($extensionQueryBuilderMock);

            // Create middleware with fresh mocks
            $middleware = new VerifyVoiceWebhookAuth(
                $cloudonixSettingsMock,
                $didNumberMock,
                $extensionMock
            );

            $request = Request::create('/api/voice/route', 'POST', [], [], [], [], json_encode(['to' => '+14155551234']));
            $request->headers->set('Authorization', 'Bearer ' . $token);

            $response = $middleware->handle($request, function () {
                return response('OK');
            });

            if ($shouldPass) {
                $this->assertEquals(200, $response->getStatusCode(), "Token '{$token}' should be accepted");
            } else {
                $this->assertEquals(401, $response->getStatusCode(), "Token '{$token}' should be rejected");
            }
        }
    }

    public function test_missing_to_number_returns_bad_request(): void
    {
        $request = Request::create('/api/voice/route', 'POST', [], [], [], [], json_encode(['from' => '1001']));
        $request->headers->set('Authorization', 'Bearer test-api-key');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('Bad request. Missing destination number.', $response->getContent());
        $this->assertStringContainsString('<Response>', $response->getContent());
        $this->assertEquals('application/xml', $response->headers->get('Content-Type'));
    }

    public function test_unable_to_identify_organization_returns_not_found(): void
    {
        // Mock all models to return null (organization not found)
        $didQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
        $didQueryBuilderMock->shouldReceive('where')
            ->with('phone_number', '+14155551234')
            ->andReturnSelf();
        $didQueryBuilderMock->shouldReceive('where')
            ->with('status', 'active')
            ->andReturnSelf();
        $didQueryBuilderMock->shouldReceive('first')
            ->andReturn(null);

        $this->didNumberMock->shouldReceive('withoutGlobalScope')
            ->with(\App\Scopes\OrganizationScope::class)
            ->andReturn($didQueryBuilderMock);

        // Mock CloudonixSettings - should not be called since org identification fails
        $this->cloudonixSettingsMock->shouldReceive('where')
            ->never();

        // Mock Extension lookups - all should return null
        $extensionQueryBuilderMock1 = Mockery::mock()->shouldIgnoreMissing();
        $extensionQueryBuilderMock1->shouldReceive('where')
            ->with('extension_number', '+14155551234')
            ->andReturnSelf();
        $extensionQueryBuilderMock1->shouldReceive('whereIn')
            ->with('type', ['user', 'ai_assistant'])
            ->andReturnSelf();
        $extensionQueryBuilderMock1->shouldReceive('where')
            ->with('status', 'active')
            ->andReturnSelf();
        $extensionQueryBuilderMock1->shouldReceive('first')
            ->andReturn(null);

        $extensionQueryBuilderMock2 = Mockery::mock()->shouldIgnoreMissing();
        $extensionQueryBuilderMock2->shouldReceive('where')
            ->with('extension_number', null)
            ->andReturnSelf();
        $extensionQueryBuilderMock2->shouldReceive('whereIn')
            ->with('type', ['user', 'ai_assistant'])
            ->andReturnSelf();
        $extensionQueryBuilderMock2->shouldReceive('where')
            ->with('status', 'active')
            ->andReturnSelf();
        $extensionQueryBuilderMock2->shouldReceive('first')
            ->andReturn(null);

        $extensionQueryBuilderMock3 = Mockery::mock()->shouldIgnoreMissing();
        $extensionQueryBuilderMock3->shouldReceive('where')
            ->with('extension_number', '+14155551234')
            ->andReturnSelf();
        $extensionQueryBuilderMock3->shouldReceive('whereIn')
            ->with('type', ['user', 'ai_assistant'])
            ->andReturnSelf();
        $extensionQueryBuilderMock3->shouldReceive('where')
            ->with('status', 'active')
            ->andReturnSelf();
        $extensionQueryBuilderMock3->shouldReceive('first')
            ->andReturn(null);

        $this->extensionMock->shouldReceive('withoutGlobalScope')
            ->with(\App\Scopes\OrganizationScope::class)
            ->andReturn($extensionQueryBuilderMock1, $extensionQueryBuilderMock2, $extensionQueryBuilderMock3);

        $request = Request::create('/api/voice/route', 'POST', [], [], [], [], json_encode(['to' => '+14155551234']));
        $request->headers->set('Authorization', 'Bearer test-api-key');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertStringContainsString('Not found. Unable to identify organization.', $response->getContent());
        $this->assertStringContainsString('<Response>', $response->getContent());
        $this->assertEquals('application/xml', $response->headers->get('Content-Type'));
    }

    public function test_organization_without_api_key_returns_config_error(): void
    {
        // Mock DID lookup
        $didMockResult = Mockery::mock(DidNumber::class)->makePartial()->shouldIgnoreMissing();
        $didMockResult->organization_id = 1;

        $didQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
        $didQueryBuilderMock->shouldReceive('where')
            ->with('phone_number', '+14155551234')
            ->andReturnSelf();
        $didQueryBuilderMock->shouldReceive('where')
            ->with('status', 'active')
            ->andReturnSelf();
        $didQueryBuilderMock->shouldReceive('first')
            ->andReturn($didMockResult);

        $this->didNumberMock->shouldReceive('withoutGlobalScope')
            ->with(\App\Scopes\OrganizationScope::class)
            ->andReturn($didQueryBuilderMock);

        // Mock CloudonixSettings to return settings without API key
        $settingsMockResult = Mockery::mock(CloudonixSettings::class)->makePartial()->shouldIgnoreMissing();
        $settingsMockResult->domain_requests_api_key = null;

        $settingsQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
        $settingsQueryBuilderMock->shouldReceive('first')
            ->andReturn($settingsMockResult);

        $this->cloudonixSettingsMock->shouldReceive('where')
            ->with('organization_id', 1)
            ->andReturn($settingsQueryBuilderMock);

        // Mock Extension to return null (not found)
        $extensionQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
        $extensionQueryBuilderMock->shouldReceive('where')
            ->with('extension_number', '+14155551234')
            ->andReturnSelf();
        $extensionQueryBuilderMock->shouldReceive('whereIn')
            ->with('type', ['user', 'ai_assistant'])
            ->andReturnSelf();
        $extensionQueryBuilderMock->shouldReceive('where')
            ->with('status', 'active')
            ->andReturnSelf();
        $extensionQueryBuilderMock->shouldReceive('first')
            ->andReturn(null);

        $this->extensionMock->shouldReceive('withoutGlobalScope')
            ->with(\App\Scopes\OrganizationScope::class)
            ->andReturn($extensionQueryBuilderMock);

        $request = Request::create('/api/voice/route', 'POST', [], [], [], [], json_encode(['to' => '+14155551234']));
        $request->headers->set('Authorization', 'Bearer test-api-key');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertStringContainsString('Configuration error. API key not configured.', $response->getContent());
        $this->assertStringContainsString('<Response>', $response->getContent());
        $this->assertEquals('application/xml', $response->headers->get('Content-Type'));
    }

    public function test_no_organization_settings_returns_config_error(): void
    {
        // Mock DID lookup
        $didMockResult = Mockery::mock(DidNumber::class)->makePartial()->shouldIgnoreMissing();
        $didMockResult->organization_id = 1;

        $didQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
        $didQueryBuilderMock->shouldReceive('where')
            ->with('phone_number', '+14155551234')
            ->andReturnSelf();
        $didQueryBuilderMock->shouldReceive('where')
            ->with('status', 'active')
            ->andReturnSelf();
        $didQueryBuilderMock->shouldReceive('first')
            ->andReturn($didMockResult);

        $this->didNumberMock->shouldReceive('withoutGlobalScope')
            ->with(\App\Scopes\OrganizationScope::class)
            ->andReturn($didQueryBuilderMock);

        // Mock CloudonixSettings to return null (no settings found)
        $settingsQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
        $settingsQueryBuilderMock->shouldReceive('first')
            ->andReturn(null);

        $this->cloudonixSettingsMock->shouldReceive('where')
            ->with('organization_id', 1)
            ->andReturn($settingsQueryBuilderMock);

        // Mock Extension to return null (not found)
        $extensionQueryBuilderMock = Mockery::mock()->shouldIgnoreMissing();
        $extensionQueryBuilderMock->shouldReceive('where')
            ->with('extension_number', '+14155551234')
            ->andReturnSelf();
        $extensionQueryBuilderMock->shouldReceive('whereIn')
            ->with('type', ['user', 'ai_assistant'])
            ->andReturnSelf();
        $extensionQueryBuilderMock->shouldReceive('where')
            ->with('status', 'active')
            ->andReturnSelf();
        $extensionQueryBuilderMock->shouldReceive('first')
            ->andReturn(null);

        $this->extensionMock->shouldReceive('withoutGlobalScope')
            ->with(\App\Scopes\OrganizationScope::class)
            ->andReturn($extensionQueryBuilderMock);

        $request = Request::create('/api/voice/route', 'POST', [], [], [], [], json_encode(['to' => '+14155551234']));
        $request->headers->set('Authorization', 'Bearer test-api-key');

        $response = $this->middleware->handle($request, function () {
            return response('OK');
        });

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertStringContainsString('Configuration error. API key not configured.', $response->getContent());
        $this->assertStringContainsString('<Response>', $response->getContent());
        $this->assertEquals('application/xml', $response->headers->get('Content-Type'));
    }
}
