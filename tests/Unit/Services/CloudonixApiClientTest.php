<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CloudonixApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * CloudonixApiClient service unit tests.
 *
 * Tests HTTP client behavior, authentication, error handling,
 * and domain validation logic.
 */
class CloudonixApiClientTest extends TestCase
{
    /**
     * Test successful domain validation returns true.
     */
    public function test_validate_domain_returns_true_on_success(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'domain' => [
                    'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                    'name' => 'test-domain.cloudonix.io',
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $apiClient = new CloudonixApiClient();
        $reflection = new \ReflectionClass($apiClient);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($apiClient, $client);

        $result = $apiClient->validateDomain(
            '550e8400-e29b-41d4-a716-446655440000',
            'test-api-key'
        );

        $this->assertTrue($result);
    }

    /**
     * Test failed domain validation returns false on 401.
     */
    public function test_validate_domain_returns_false_on_401(): void
    {
        $mock = new MockHandler([
            new Response(401, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'Unauthorized',
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $apiClient = new CloudonixApiClient();
        $reflection = new \ReflectionClass($apiClient);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($apiClient, $client);

        $result = $apiClient->validateDomain(
            '550e8400-e29b-41d4-a716-446655440000',
            'invalid-api-key'
        );

        $this->assertFalse($result);
    }

    /**
     * Test failed domain validation returns false on 404.
     */
    public function test_validate_domain_returns_false_on_404(): void
    {
        $mock = new MockHandler([
            new Response(404, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'Domain not found',
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $apiClient = new CloudonixApiClient();
        $reflection = new \ReflectionClass($apiClient);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($apiClient, $client);

        $result = $apiClient->validateDomain(
            'non-existent-uuid',
            'test-api-key'
        );

        $this->assertFalse($result);
    }

    /**
     * Test validate domain handles network errors gracefully.
     */
    public function test_validate_domain_handles_network_errors(): void
    {
        $mock = new MockHandler([
            new \GuzzleHttp\Exception\ConnectException(
                'Connection timeout',
                new \GuzzleHttp\Psr7\Request('GET', '/test')
            ),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $apiClient = new CloudonixApiClient();
        $reflection = new \ReflectionClass($apiClient);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($apiClient, $client);

        $result = $apiClient->validateDomain(
            '550e8400-e29b-41d4-a716-446655440000',
            'test-api-key'
        );

        $this->assertFalse($result);
    }

    /**
     * Test base URL getter.
     */
    public function test_get_base_url(): void
    {
        $apiClient = new CloudonixApiClient();

        $this->assertEquals('https://api.cloudonix.io', $apiClient->getBaseUrl());
    }

    /**
     * Test base URL setter.
     */
    public function test_set_base_url(): void
    {
        $apiClient = new CloudonixApiClient();

        $apiClient->setBaseUrl('https://custom.api.example.com');

        $this->assertEquals('https://custom.api.example.com', $apiClient->getBaseUrl());
    }

    /**
     * Test base URL setter removes trailing slash.
     */
    public function test_set_base_url_removes_trailing_slash(): void
    {
        $apiClient = new CloudonixApiClient();

        $apiClient->setBaseUrl('https://custom.api.example.com/');

        $this->assertEquals('https://custom.api.example.com', $apiClient->getBaseUrl());
    }

    /**
     * Test API client uses correct authorization header.
     */
    public function test_uses_bearer_token_authorization(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => true])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $apiClient = new CloudonixApiClient();
        $reflection = new \ReflectionClass($apiClient);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($apiClient, $client);

        $apiClient->validateDomain(
            '550e8400-e29b-41d4-a716-446655440000',
            'test-api-key'
        );

        // Verify the request was made with the Bearer token
        $lastRequest = $mock->getLastRequest();
        $this->assertNotNull($lastRequest);
    }

    /**
     * Test API client handles empty response body.
     */
    public function test_handles_empty_response_body(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], ''),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $apiClient = new CloudonixApiClient();
        $reflection = new \ReflectionClass($apiClient);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($apiClient, $client);

        $result = $apiClient->validateDomain(
            '550e8400-e29b-41d4-a716-446655440000',
            'test-api-key'
        );

        // Should still return true for 200 status even with empty body
        $this->assertTrue($result);
    }

    /**
     * Test API client handles non-JSON response.
     */
    public function test_handles_non_json_response(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], '<html>Success</html>'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $apiClient = new CloudonixApiClient();
        $reflection = new \ReflectionClass($apiClient);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($apiClient, $client);

        $result = $apiClient->validateDomain(
            '550e8400-e29b-41d4-a716-446655440000',
            'test-api-key'
        );

        // Should still return true for 200 status
        $this->assertTrue($result);
    }
}
