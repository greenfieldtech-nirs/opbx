<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cloudonix API Client for domain validation and management.
 *
 * This client handles authentication and communication with the Cloudonix REST API.
 * Reference: https://developers.cloudonix.com/Documentation/core-api
 */
class CloudonixApiClient
{
    /**
     * The base URL for the Cloudonix API.
     */
    private string $baseUrl = 'https://api.cloudonix.io';

    /**
     * The HTTP client instance.
     */
    private Client $client;

    /**
     * Create a new CloudonixApiClient instance.
     */
    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 15,
            'connect_timeout' => 5,
            'http_errors' => false, // Handle errors manually
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Validate domain credentials by fetching domain details.
     *
     * Makes a GET request to /customers/self/domains/{domain-uuid}
     * to verify that the API key is valid and has access to the domain.
     *
     * @param string $domainUuid The domain UUID to validate
     * @param string $apiKey The API key (Bearer token) to authenticate with
     * @return array{valid: bool, profile: array<string, mixed>|null} Validation result with domain profile data
     */
    public function validateDomain(string $domainUuid, string $apiKey): array
    {
        $requestId = (string) Str::uuid();

        try {
            Log::info('Validating Cloudonix domain credentials', [
                'request_id' => $requestId,
                'domain_uuid' => $domainUuid,
                'api_key_prefix' => substr($apiKey, 0, 4) . '...',
            ]);

            $response = $this->makeRequest(
                'GET',
                "/customers/self/domains/{$domainUuid}",
                $apiKey
            );

            $statusCode = $response['status_code'] ?? 0;
            $success = $statusCode === 200;
            $domainProfile = $response['body'] ?? null;

            Log::info('Cloudonix domain validation result', [
                'request_id' => $requestId,
                'domain_uuid' => $domainUuid,
                'status_code' => $statusCode,
                'success' => $success,
                'has_profile' => $domainProfile !== null,
            ]);

            return [
                'valid' => $success,
                'profile' => $domainProfile,
            ];
        } catch (\Exception $e) {
            Log::error('Cloudonix domain validation failed', [
                'request_id' => $requestId,
                'domain_uuid' => $domainUuid,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return [
                'valid' => false,
                'profile' => null,
            ];
        }
    }

    /**
     * Make an authenticated request to the Cloudonix API.
     *
     * @param string $method The HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint The API endpoint (e.g., "/customers/self/domains/{uuid}")
     * @param string $apiKey The API key (Bearer token)
     * @param array<string, mixed> $data The request body data
     * @return array{status_code: int, body: array<string, mixed>|null, error: string|null}
     */
    private function makeRequest(
        string $method,
        string $endpoint,
        string $apiKey,
        array $data = []
    ): array {
        $requestId = (string) Str::uuid();

        try {
            $options = [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                ],
            ];

            // Add request body for non-GET requests
            if (!empty($data) && $method !== 'GET') {
                $options['json'] = $data;
            }

            Log::debug('Making Cloudonix API request', [
                'request_id' => $requestId,
                'method' => $method,
                'endpoint' => $endpoint,
                'has_data' => !empty($data),
            ]);

            $response = $this->client->request($method, $endpoint, $options);

            $statusCode = $response->getStatusCode();
            $body = null;
            $contentType = $response->getHeaderLine('Content-Type');

            // Parse JSON response if present
            if (str_contains($contentType, 'application/json')) {
                $bodyContents = $response->getBody()->getContents();
                if (!empty($bodyContents)) {
                    $body = json_decode($bodyContents, true);
                }
            }

            Log::debug('Cloudonix API response received', [
                'request_id' => $requestId,
                'status_code' => $statusCode,
                'content_type' => $contentType,
            ]);

            return [
                'status_code' => $statusCode,
                'body' => $body,
                'error' => null,
            ];
        } catch (GuzzleException $e) {
            Log::error('Cloudonix API request failed', [
                'request_id' => $requestId,
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return [
                'status_code' => 0,
                'body' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update domain profile settings in Cloudonix.
     *
     * Makes a PUT request to /customers/self/domains/{domain-uuid}
     * to update domain configuration settings like call-timeout and recording format.
     *
     * @param string $domainUuid The domain UUID to update
     * @param string $apiKey The API key (Bearer token) to authenticate with
     * @param array<string, mixed> $profileData Profile settings to update (call-timeout, recording-media-type, etc.)
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function updateDomain(string $domainUuid, string $apiKey, array $profileData): array
    {
        $requestId = (string) Str::uuid();

        try {
            Log::info('Updating Cloudonix domain profile', [
                'request_id' => $requestId,
                'domain_uuid' => $domainUuid,
                'api_key_prefix' => substr($apiKey, 0, 4) . '...',
                'profile_data' => $profileData,
            ]);

            $response = $this->makeRequest(
                'PUT',
                "/customers/self/domains/{$domainUuid}",
                $apiKey,
                ['profile' => $profileData]
            );

            $statusCode = $response['status_code'] ?? 0;
            $success = $statusCode >= 200 && $statusCode < 300;
            $responseBody = $response['body'] ?? null;

            Log::info('Cloudonix domain update result', [
                'request_id' => $requestId,
                'domain_uuid' => $domainUuid,
                'status_code' => $statusCode,
                'success' => $success,
            ]);

            if (!$success) {
                $errorMessage = $responseBody['message'] ?? $response['error'] ?? 'Unknown error';

                return [
                    'success' => false,
                    'message' => "Failed to update Cloudonix domain: {$errorMessage}",
                    'data' => $responseBody,
                ];
            }

            return [
                'success' => true,
                'message' => 'Domain profile updated successfully in Cloudonix.',
                'data' => $responseBody,
            ];
        } catch (\Exception $e) {
            Log::error('Cloudonix domain update failed', [
                'request_id' => $requestId,
                'domain_uuid' => $domainUuid,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return [
                'success' => false,
                'message' => "Exception during Cloudonix update: {$e->getMessage()}",
                'data' => null,
            ];
        }
    }

    /**
     * Get the base URL for the Cloudonix API.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Set a custom base URL for the Cloudonix API.
     *
     * Useful for testing or custom deployments.
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 15,
            'connect_timeout' => 5,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        return $this;
    }
}
