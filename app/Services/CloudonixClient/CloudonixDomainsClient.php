<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use Illuminate\Support\Facades\Log;

/**
 * Cloudonix API client for domain management operations.
 *
 * Handles domain validation, profile updates, and voice application management.
 *
 * @see https://developers.cloudonix.com/cloudonixRestOpenAPI
 */
class CloudonixDomainsClient extends CloudonixBaseClient
{
    /**
     * Validate domain credentials by fetching domain details.
     *
     * Makes a GET request to /customers/self/domains/{domain-uuid}
     * to verify that the API key is valid and has access to the domain.
     *
     * @param  string  $domainUuid  The domain UUID to validate
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @return array{valid: bool, profile: array<string, mixed>|null} Validation result with domain profile data
     */
    public function validateDomain(string $domainUuid, string $apiKey): array
    {
        return self::validateDomainCredentials($domainUuid, $apiKey);
    }

    /**
     * Static method to validate domain credentials without requiring client instantiation.
     *
     * This allows validation of credentials before saving them to the database.
     *
     * @param  string  $domainUuid  The domain UUID to validate
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @return array{valid: bool, profile: array<string, mixed>|null} Validation result with domain profile data
     */
    public static function validateDomainCredentials(string $domainUuid, string $apiKey): array
    {
        try {
            Log::info('Validating Cloudonix domain credentials', [
                'domain_uuid' => $domainUuid,
                'api_key_prefix' => substr($apiKey, 0, 4).'...',
            ]);

            $baseUrl = config('cloudonix.api.base_url');
            if (empty($baseUrl)) {
                throw new \RuntimeException(
                    'Cloudonix API base URL is not configured. '.
                    'Please set CLOUDONIX_API_BASE_URL in your .env file (e.g., https://api.cloudonix.io)'
                );
            }

            $timeout = (int) config('cloudonix.api.timeout', 30);

            // Create temporary client with provided credentials
            $tempClient = \Illuminate\Support\Facades\Http::timeout($timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->baseUrl(rtrim($baseUrl, '/'));

            $response = $tempClient->get("/customers/self/domains/{$domainUuid}");

            $success = $response->successful();
            $domainProfile = $success ? $response->json() : null;

            Log::info('Cloudonix domain validation result', [
                'domain_uuid' => $domainUuid,
                'status_code' => $response->status(),
                'success' => $success,
                'has_profile' => $domainProfile !== null,
            ]);

            return [
                'valid' => $success,
                'profile' => $domainProfile,
            ];
        } catch (\Exception $e) {
            Log::error('Cloudonix domain validation failed', [
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
     * Update domain profile settings in Cloudonix.
     *
     * Makes a PUT request to /customers/self/domains/{domain-uuid}
     * to update domain configuration settings like call-timeout and recording format.
     *
     * @param  string  $domainUuid  The domain UUID to update
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @param  array<string, mixed>  $profileData  Profile settings to update (call-timeout, recording-media-type, etc.)
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function updateDomain(string $domainUuid, string $apiKey, array $profileData): array
    {
        try {
            Log::info('Updating Cloudonix domain profile', [
                'domain_uuid' => $domainUuid,
                'api_key_prefix' => substr($apiKey, 0, 4).'...',
                'profile_data' => $profileData,
            ]);

            // Create temporary client with provided credentials
            $tempClient = $this->createTemporaryClient($apiKey);

            $response = $tempClient->put(
                "/customers/self/domains/{$domainUuid}",
                ['profile' => $profileData]
            );

            $success = $response->successful();
            $responseBody = $response->json();

            Log::info('Cloudonix domain update result', [
                'domain_uuid' => $domainUuid,
                'status_code' => $response->status(),
                'success' => $success,
            ]);

            if (! $success) {
                $errorMessage = $responseBody['message'] ?? $response->body() ?? 'Unknown error';

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
     * Create a voice application in Cloudonix.
     *
     * Makes a POST request to /customers/{customer-id}/domains/{domain-id}/applications
     * to create a new CXML voice application for call routing.
     *
     * @param  string  $domainUuid  The domain UUID
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @param  array<string, mixed>  $applicationData  Application configuration (name, type, url, method, profile)
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function createVoiceApplication(string $domainUuid, string $apiKey, array $applicationData): array
    {
        try {
            Log::info('Creating Cloudonix voice application', [
                'domain_uuid' => $domainUuid,
                'api_key_prefix' => substr($apiKey, 0, 4).'...',
                'application_name' => $applicationData['name'] ?? null,
            ]);

            // Create temporary client with provided credentials
            $tempClient = $this->createTemporaryClient($apiKey);

            $response = $tempClient->post(
                "/customers/{$this->getCustomerId()}/domains/{$domainUuid}/applications",
                $applicationData
            );

            $success = $response->successful();
            $responseBody = $response->json();

            Log::info('Cloudonix voice application creation result', [
                'domain_uuid' => $domainUuid,
                'status_code' => $response->status(),
                'success' => $success,
                'application_id' => $responseBody['id'] ?? null,
            ]);

            if (! $success) {
                $errorMessage = $responseBody['message'] ?? $response->body() ?? 'Unknown error';

                return [
                    'success' => false,
                    'message' => "Failed to create voice application: {$errorMessage}",
                    'data' => $responseBody,
                ];
            }

            return [
                'success' => true,
                'message' => 'Voice application created successfully.',
                'data' => $responseBody,
            ];
        } catch (\Exception $e) {
            Log::error('Cloudonix voice application creation failed', [
                'domain_uuid' => $domainUuid,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return [
                'success' => false,
                'message' => "Exception during voice application creation: {$e->getMessage()}",
                'data' => null,
            ];
        }
    }

    /**
     * Update the default application for a domain.
     *
     * Makes a PUT request to /customers/{customer-id}/domains/{domain-id}
     * to set the default application that will handle incoming calls.
     *
     * @param  string  $domainUuid  The domain UUID
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @param  int  $applicationId  The application ID to set as default
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function updateDomainDefaultApplication(string $domainUuid, string $apiKey, int $applicationId): array
    {
        try {
            Log::info('Updating Cloudonix domain default application', [
                'domain_uuid' => $domainUuid,
                'api_key_prefix' => substr($apiKey, 0, 4).'...',
                'application_id' => $applicationId,
            ]);

            // Create temporary client with provided credentials
            $tempClient = $this->createTemporaryClient($apiKey);

            $response = $tempClient->put(
                "/customers/{$this->getCustomerId()}/domains/{$domainUuid}",
                ['defaultApplication' => $applicationId]
            );

            $success = $response->successful();
            $responseBody = $response->json();

            Log::info('Cloudonix domain default application update result', [
                'domain_uuid' => $domainUuid,
                'status_code' => $response->status(),
                'success' => $success,
            ]);

            if (! $success) {
                $errorMessage = $responseBody['message'] ?? $response->body() ?? 'Unknown error';

                return [
                    'success' => false,
                    'message' => "Failed to update default application: {$errorMessage}",
                    'data' => $responseBody,
                ];
            }

            return [
                'success' => true,
                'message' => 'Default application updated successfully.',
                'data' => $responseBody,
            ];
        } catch (\Exception $e) {
            Log::error('Cloudonix domain default application update failed', [
                'domain_uuid' => $domainUuid,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return [
                'success' => false,
                'message' => "Exception during default application update: {$e->getMessage()}",
                'data' => null,
            ];
        }
    }

    /**
     * Update a voice application in Cloudonix.
     *
     * Makes a PUT request to /customers/{customer-id}/domains/{domain-id}/applications/{application-name}
     * to update the voice application configuration, including the URL endpoint.
     *
     * @param  string  $domainUuid  The domain UUID
     * @param  string  $apiKey  The API key (Bearer token) to authenticate with
     * @param  string  $applicationName  The voice application name to update
     * @param  array<string, mixed>  $applicationData  Application configuration to update (url, method, profile, etc.)
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function updateVoiceApplication(string $domainUuid, string $apiKey, string $applicationName, array $applicationData): array
    {
        try {
            Log::info('Updating Cloudonix voice application', [
                'domain_uuid' => $domainUuid,
                'api_key_prefix' => substr($apiKey, 0, 4).'...',
                'application_name' => $applicationName,
                'application_data' => $applicationData,
            ]);

            // Create temporary client with provided credentials
            $tempClient = $this->createTemporaryClient($apiKey);

            $response = $tempClient->put(
                "/customers/{$this->getCustomerId()}/domains/{$domainUuid}/applications/".rawurlencode($applicationName),
                $applicationData
            );

            $success = $response->successful();
            $responseBody = $response->json();

            Log::info('Cloudonix voice application update result', [
                'domain_uuid' => $domainUuid,
                'application_name' => $applicationName,
                'status_code' => $response->status(),
                'success' => $success,
            ]);

            if (! $success) {
                $errorMessage = $responseBody['message'] ?? $response->body() ?? 'Unknown error';

                return [
                    'success' => false,
                    'message' => "Failed to update voice application: {$errorMessage}",
                    'data' => $responseBody,
                ];
            }

            return [
                'success' => true,
                'message' => 'Voice application updated successfully.',
                'data' => $responseBody,
            ];
        } catch (\Exception $e) {
            Log::error('Cloudonix voice application update failed', [
                'domain_uuid' => $domainUuid,
                'application_name' => $applicationName,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return [
                'success' => false,
                'message' => "Exception during voice application update: {$e->getMessage()}",
                'data' => null,
            ];
        }
    }
}
