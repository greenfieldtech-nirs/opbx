<?php

declare(strict_types=1);

namespace Tests\Integration\Services\AutoDialer;

use App\Services\AutoDialer\AutoDialerCloudonixService;
use App\Services\CloudonixClient\CloudonixClient;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Auto Dialer Cloudonix Integration Test
 *
 * This test validates the actual Cloudonix API connection.
 * It requires the following environment variables:
 * - CLOUDONIX_TEST_DOMAIN
 * - CLOUDONIX_TEST_API_KEY
 *
 * To skip this test, run: php artisan test --exclude-group=integration
 *
 * @group integration
 * @group cloudonix
 */
class AutoDialerCloudonixIntegrationTest extends TestCase
{
    private ?AutoDialerCloudonixService $service = null;

    private ?CloudonixClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip if credentials are not configured
        $domain = getenv('CLOUDONIX_TEST_DOMAIN');
        $apiKey = getenv('CLOUDONIX_TEST_API_KEY');

        if (empty($domain) || empty($apiKey)) {
            $this->markTestSkipped(
                'Cloudonix test credentials not configured. '.
                'Set CLOUDONIX_TEST_DOMAIN and CLOUDONIX_TEST_API_KEY environment variables.'
            );
        }

        // Configure Cloudonix settings
        Config::set('cloudonix.api.base_url', 'https://api.cloudonix.io');
        Config::set('cloudonix.api.timeout', 30);

        // Create a mock CloudonixSettings for the client
        $settings = new class($domain, $apiKey)
        {
            public string $domain_uuid;

            public string $domain_api_key;

            public function __construct(string $uuid, string $key)
            {
                $this->domain_uuid = $uuid;
                $this->domain_api_key = $key;
            }
        };

        $this->client = new CloudonixClient($settings);
        $this->service = new AutoDialerCloudonixService($this->client);
    }

    /**
     * Test that we can validate credentials against Cloudonix API.
     */
    public function test_validate_credentials_with_real_api(): void
    {
        $domain = getenv('CLOUDONIX_TEST_DOMAIN');
        $apiKey = getenv('CLOUDONIX_TEST_API_KEY');

        $result = AutoDialerCloudonixService::validateCredentials($domain, $apiKey);

        $this->assertTrue(
            $result['valid'],
            'Cloudonix credential validation failed: '.json_encode($result)
        );
        $this->assertNotNull($result['profile']);
        $this->assertArrayHasKey('domain', $result['profile']);
    }

    /**
     * Test that invalid credentials are rejected.
     */
    public function test_validate_credentials_with_invalid_key(): void
    {
        $domain = getenv('CLOUDONIX_TEST_DOMAIN');
        $invalidKey = 'INVALID_API_KEY_12345';

        $result = AutoDialerCloudonixService::validateCredentials($domain, $invalidKey);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['profile']);
    }

    /**
     * Test that we can fetch call status (may return null for non-existent calls).
     */
    public function test_get_call_status_handles_non_existent_call(): void
    {
        $result = $this->service->getCallStatus('non-existent-call-id-12345');

        // Should return null for non-existent call (404 from API)
        $this->assertNull($result);
    }

    /**
     * Test circuit breaker status is available.
     */
    public function test_circuit_breaker_status_is_available(): void
    {
        $status = $this->client->getCircuitBreakerStatus();

        $this->assertArrayHasKey('state', $status);
        $this->assertArrayHasKey('failures', $status);
        $this->assertArrayHasKey('last_failure_time', $status);
    }

    /**
     * Test that the client can be instantiated with real credentials.
     */
    public function test_client_can_be_instantiated(): void
    {
        $this->assertNotNull($this->client);
        $this->assertNotNull($this->service);
    }
}
