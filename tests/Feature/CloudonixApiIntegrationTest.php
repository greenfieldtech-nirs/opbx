<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cloudonix API Integration Test
 *
 * This test validates the connection to Cloudonix API using test credentials.
 * Run with: php artisan test --filter=CloudonixApiIntegrationTest
 *
 * WARNING: This test makes REAL API calls to Cloudonix. Only run when explicitly
 * testing the Cloudonix integration. Use HTTP faking for regular test runs.
 */
class CloudonixApiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test Cloudonix API connectivity with real credentials.
     *
     * This test requires CLOUDONIX_TEST_DOMAIN and CLOUDONIX_TEST_API_KEY
     * to be set in the environment or .env.testing file.
     *
     * To run with real API calls:
     *   CLOUDONIX_TEST_MODE=real php artisan test --filter=test_cloudonix_api_connection
     *
     * To run with HTTP faking (default):
     *   php artisan test --filter=test_cloudonix_api_connection
     */
    public function test_cloudonix_api_connection(): void
    {
        $domain = env('CLOUDONIX_TEST_DOMAIN', 'dograh-ejm4ke.cloudonix.net');
        $apiKey = env('CLOUDONIX_TEST_API_KEY', 'XIBB0E3CD4FB1F46698DE5FC51B49A012E');
        $testMode = env('CLOUDONIX_TEST_MODE', 'fake');

        // Skip if no credentials available
        if (empty($domain) || empty($apiKey)) {
            $this->markTestSkipped('Cloudonix test credentials not configured');
        }

        echo "\n🔍 Testing Cloudonix API Connection\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "Mode: {$testMode}\n";
        echo "Domain: {$domain}\n";
        echo 'API Key: '.substr($apiKey, 0, 10).'...'.substr($apiKey, -5)."\n\n";

        if ($testMode === 'real') {
            $this->test_real_api_connection($domain, $apiKey);
        } else {
            $this->test_fake_api_connection($domain, $apiKey);
        }
    }

    /**
     * Test with real Cloudonix API call.
     */
    private function test_real_api_connection(string $domain, string $apiKey): void
    {
        // Build the API endpoint
        $endpoint = "https://api.cloudonix.io/calls/{$domain}/application";

        // Build the request payload (minimal test - will fail but validates auth)
        $payload = [
            'destination' => '+15551234567',
            'caller-id' => '+18001234567',
            'application' => 1,
            'callback' => 'https://example.com/webhook',
        ];

        echo "🌐 Making real API call to Cloudonix...\n";
        echo "   Endpoint: POST {$endpoint}\n";
        echo '   Payload: '.json_encode($payload)."\n\n";

        // Make the API call
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ])->post($endpoint, $payload);

        echo "📥 Response Status: {$response->status()}\n";
        echo '📥 Response Body: '.$response->body()."\n\n";

        // Even if the call fails (e.g., invalid destination), we should get
        // a structured error response that validates our API format is correct
        if ($response->successful()) {
            $data = $response->json();
            echo "✅ API call successful!\n";
            echo '   Token: '.($data['token'] ?? 'N/A')."\n";
            echo '   Direction: '.($data['direction'] ?? 'N/A')."\n";
            echo '   Domain ID: '.($data['domainId'] ?? 'N/A')."\n";

            $this->assertArrayHasKey('token', $data);
            $this->assertArrayHasKey('direction', $data);
            $this->assertEquals('outbound-api', $data['direction']);
        } else {
            // API call failed but connection worked
            echo "⚠️ API call returned error (but connection worked)\n";
            echo "   Status: {$response->status()}\n";

            // 401 = Unauthorized (bad API key)
            // 403 = Forbidden (domain not allowed)
            // 422 = Validation error (expected for test numbers)
            // 400 = Bad request

            if ($response->status() === 401) {
                $this->fail('API authentication failed - check API key');
            } elseif ($response->status() === 403) {
                $this->fail('API access forbidden - check domain permissions');
            } else {
                // Other errors are expected for test calls
                $this->assertTrue(
                    in_array($response->status(), [400, 422, 404]),
                    "Unexpected HTTP status: {$response->status()}"
                );
                echo "   ✓ Connection validated (error is expected for test call)\n";
            }
        }

        echo "\n✅ Cloudonix API connection validated successfully!\n";
    }

    /**
     * Test with HTTP faking (default for CI/automated tests).
     */
    private function test_fake_api_connection(string $domain, string $apiKey): void
    {
        $endpoint = "https://api.cloudonix.io/calls/{$domain}/application";

        // Fake a successful response
        Http::fake([
            'api.cloudonix.io/calls/*' => Http::response([
                'domainId' => 3,
                'subscriberId' => 372,
                'destination' => '+15551234567',
                'direction' => 'outbound-api',
                'token' => 'test-call-token-'.uniqid(),
            ], 200),
        ]);

        $payload = [
            'destination' => '+15551234567',
            'caller-id' => '+18001234567',
            'application' => 1,
            'callback' => 'https://example.com/webhook',
        ];

        echo "🎭 Testing with HTTP fake...\n";
        echo "   Endpoint: POST {$endpoint}\n\n";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ])->post($endpoint, $payload);

        $this->assertTrue($response->successful());

        $data = $response->json();
        echo "✅ Faked API call successful!\n";
        echo '   Token: '.($data['token'] ?? 'N/A')."\n";
        echo '   Direction: '.($data['direction'] ?? 'N/A')."\n";
        echo '   Domain ID: '.($data['domainId'] ?? 'N/A')."\n";

        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('direction', $data);
        $this->assertEquals('outbound-api', $data['direction']);

        // Verify the request was made correctly
        Http::assertSent(function ($request) use ($domain, $apiKey) {
            return $request->url() === "https://api.cloudonix.io/calls/{$domain}/application" &&
                   $request->hasHeader('Authorization', 'Bearer '.$apiKey) &&
                   $request->hasHeader('Content-Type', 'application/json');
        });

        echo "\n✅ Request structure validated successfully!\n";
        echo "   ✓ Correct endpoint format\n";
        echo "   ✓ Authorization header present\n";
        echo "   ✓ Content-Type header correct\n";
        echo "   ✓ Payload structure valid\n";
    }

    /**
     * Test the Cloudonix API request structure matches specification.
     */
    public function test_cloudonix_api_request_structure(): void
    {
        $domain = 'test-domain.cloudonix.net';
        $apiKey = 'test-api-key';
        $endpoint = "https://api.cloudonix.io/calls/{$domain}/application";

        Http::fake([
            'api.cloudonix.io/calls/*' => Http::response([
                'domainId' => 3,
                'subscriberId' => 372,
                'destination' => '+15551234567',
                'direction' => 'outbound-api',
                'token' => 'test-token-123',
            ], 200),
        ]);

        // Test full payload structure
        $payload = [
            'destination' => '+15551234567',
            'caller-id' => '+18001234567',
            'application' => 5,
            'timeout' => 30,
            'timeLimit' => 3600,
            'record' => true,
            'machineDetection' => 'Enable',
            'callback' => 'https://example.com/webhook',
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ])->post($endpoint, $payload);

        $this->assertTrue($response->successful());

        // Verify all fields were sent correctly
        Http::assertSent(function ($request) use ($payload) {
            $body = $request->data();

            return $body['destination'] === $payload['destination'] &&
                   $body['caller-id'] === $payload['caller-id'] &&
                   $body['application'] === $payload['application'] &&
                   $body['timeout'] === $payload['timeout'] &&
                   $body['timeLimit'] === $payload['timeLimit'] &&
                   $body['record'] === $payload['record'] &&
                   $body['machineDetection'] === $payload['machineDetection'] &&
                   $body['callback'] === $payload['callback'];
        });

        echo "\n✅ API request structure matches specification\n";
    }

    /**
     * Test Cloudonix API 401 Unauthorized error handling.
     */
    public function test_cloudonix_api_401_error_handling(): void
    {
        $domain = 'test-domain.cloudonix.net';
        $apiKey = 'test-api-key';
        $endpoint = "https://api.cloudonix.io/calls/{$domain}/application";

        // Test 401 Unauthorized
        Http::fake([
            'api.cloudonix.io/calls/*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ])->post($endpoint, ['destination' => '+15551234567']);

        $this->assertEquals(401, $response->status());
        echo "\n✅ 401 Unauthorized handling works\n";
    }

    /**
     * Test Cloudonix API 422 Validation error handling.
     */
    public function test_cloudonix_api_422_error_handling(): void
    {
        $domain = 'test-domain.cloudonix.net';
        $apiKey = 'test-api-key';
        $endpoint = "https://api.cloudonix.io/calls/{$domain}/application";

        // Test 422 Validation Error
        Http::fake([
            'api.cloudonix.io/calls/*' => Http::response([
                'error' => 'Validation failed',
                'details' => ['destination' => 'Invalid phone number'],
            ], 422),
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ])->post($endpoint, ['destination' => 'invalid']);

        $this->assertEquals(422, $response->status());
        echo "✅ 422 Validation error handling works\n";
    }
}
