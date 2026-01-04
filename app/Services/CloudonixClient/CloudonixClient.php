<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\CircuitBreaker\CircuitBreakerOpenException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for Cloudonix REST API.
 *
 * @see https://developers.cloudonix.com/Documentation/core-api
 * @see https://developers.cloudonix.com/cloudonixRestOpenAPI
 */
class CloudonixClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;
    private ?string $domainUuid;
    private ?string $customerId;
    private CircuitBreaker $circuitBreaker;

    /**
     * Create a new CloudonixClient instance.
     *
     * @param CloudonixSettings|Organization|null $settings Organization settings or Organization model
     * @param bool $requireCredentials Whether to require credentials at instantiation (default: true)
     * @throws \RuntimeException If API token is not configured and credentials are required
     */
    public function __construct(CloudonixSettings|Organization|null $settings = null, bool $requireCredentials = true)
    {
        // Validate base URL configuration
        $baseUrl = config('cloudonix.api.base_url');
        if (empty($baseUrl)) {
            throw new \RuntimeException(
                'Cloudonix API base URL is not configured. ' .
                'Please set CLOUDONIX_API_BASE_URL in your .env file (e.g., https://api.cloudonix.io)'
            );
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = (int) config('cloudonix.api.timeout', 30);
        $this->customerId = 'self'; // As per Cloudonix API docs

        if ($settings instanceof Organization) {
            // Load CloudonixSettings from Organization
            $settings = $settings->cloudonixSettings;
        }

        if ($settings instanceof CloudonixSettings) {
            // Use organization-specific credentials
            $this->token = $settings->domain_api_key;
            $this->domainUuid = $settings->domain_uuid;

            if ($requireCredentials && (empty($this->token) || empty($this->domainUuid))) {
                throw new \RuntimeException(
                    'Organization Cloudonix settings are not properly configured. ' .
                    'Both domain_api_key and domain_uuid are required. ' .
                    'Please configure them in the Settings page.'
                );
            }
        } else {
            // Fall back to global config for backward compatibility (call management)
            $this->token = config('cloudonix.api.token', '');
            $this->domainUuid = null;

            if ($requireCredentials && empty($this->token)) {
                throw new \RuntimeException('Cloudonix API token is not configured');
            }
        }

        // Initialize circuit breaker with configured thresholds
        $this->circuitBreaker = new CircuitBreaker(
            serviceName: 'cloudonix-api',
            failureThreshold: (int) config('circuit-breaker.cloudonix.failure_threshold', 5),
            timeoutSeconds: (int) config('circuit-breaker.cloudonix.timeout', 30),
            retryAfterSeconds: (int) config('circuit-breaker.cloudonix.retry_after', 60)
        );
    }

    /**
     * Get HTTP client with authorization header.
     */
    protected function client(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->baseUrl($this->baseUrl);
    }

    /**
     * Execute an API call with circuit breaker protection.
     *
     * @param callable $callback The API call to execute
     * @param string $cacheKey Optional cache key for fallback data
     * @param mixed $fallbackValue Fallback value if circuit is open and no cache
     * @return mixed
     */
    protected function withCircuitBreaker(callable $callback, ?string $cacheKey = null, mixed $fallbackValue = null): mixed
    {
        try {
            return $this->circuitBreaker->call(
                callback: $callback,
                fallback: function () use ($cacheKey, $fallbackValue) {
                    // Try cached data first
                    if ($cacheKey && Cache::has($cacheKey)) {
                        Log::info('Circuit breaker: returning cached data', [
                            'cache_key' => $cacheKey,
                        ]);

                        return Cache::get($cacheKey);
                    }

                    // Return fallback value
                    Log::warning('Circuit breaker: returning fallback value', [
                        'has_cache_key' => $cacheKey !== null,
                        'has_fallback' => $fallbackValue !== null,
                    ]);

                    return $fallbackValue;
                }
            );
        } catch (CircuitBreakerOpenException $e) {
            // Circuit is open and no fallback available
            Log::error('Circuit breaker open - API call failed', [
                'service' => 'cloudonix-api',
                'cache_key' => $cacheKey,
            ]);

            return $fallbackValue;
        }
    }

    /**
     * Get circuit breaker status for monitoring.
     *
     * @return array<string, mixed>
     */
    public function getCircuitBreakerStatus(): array
    {
        return $this->circuitBreaker->getStatus();
    }

    /**
     * Manually reset the circuit breaker.
     */
    public function resetCircuitBreaker(): void
    {
        $this->circuitBreaker->reset();
    }

    /**
     * Get call status by call ID.
     *
     * @param string $callId The Cloudonix call ID
     * @return array<string, mixed>|null
     */
    public function getCallStatus(string $callId): ?array
    {
        $cacheKey = "cloudonix:call_status:{$callId}";

        return $this->withCircuitBreaker(
            callback: function () use ($callId, $cacheKey) {
                try {
                    $response = $this->client()
                        ->get("/calls/{$callId}");

                    if ($response->successful()) {
                        $data = $response->json();

                        // Cache successful responses for 30 seconds
                        Cache::put($cacheKey, $data, now()->addSeconds(30));

                        return $data;
                    }

                    Log::warning('Failed to get call status from Cloudonix', [
                        'call_id' => $callId,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('Exception while getting call status from Cloudonix', [
                        'call_id' => $callId,
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: $cacheKey,
            fallbackValue: null
        );
    }

    /**
     * Get CDR (Call Detail Record) by call ID.
     *
     * @param string $callId The Cloudonix call ID
     * @return array<string, mixed>|null
     */
    public function getCallCdr(string $callId): ?array
    {
        $cacheKey = "cloudonix:cdr:{$callId}";

        return $this->withCircuitBreaker(
            callback: function () use ($callId, $cacheKey) {
                try {
                    $response = $this->client()
                        ->get("/calls/{$callId}/cdr");

                    if ($response->successful()) {
                        $data = $response->json();

                        // Cache CDRs for 5 minutes (they don't change once created)
                        Cache::put($cacheKey, $data, now()->addMinutes(5));

                        return $data;
                    }

                    Log::warning('Failed to get CDR from Cloudonix', [
                        'call_id' => $callId,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('Exception while getting CDR from Cloudonix', [
                        'call_id' => $callId,
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: $cacheKey,
            fallbackValue: null
        );
    }

    /**
     * Hangup a call.
     *
     * @param string $callId The Cloudonix call ID
     */
    public function hangupCall(string $callId): bool
    {
        return $this->withCircuitBreaker(
            callback: function () use ($callId) {
                try {
                    $response = $this->client()
                        ->delete("/calls/{$callId}");

                    if ($response->successful()) {
                        Log::info('Successfully hung up call', [
                            'call_id' => $callId,
                        ]);

                        return true;
                    }

                    Log::warning('Failed to hangup call', [
                        'call_id' => $callId,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return false;
                } catch (\Exception $e) {
                    Log::error('Exception while hanging up call', [
                        'call_id' => $callId,
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: null, // No caching for write operations
            fallbackValue: false
        );
    }

    /**
     * Get list of calls with optional filters.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function listCalls(array $filters = []): ?array
    {
        $cacheKey = 'cloudonix:calls:' . md5(json_encode($filters));

        return $this->withCircuitBreaker(
            callback: function () use ($filters, $cacheKey) {
                try {
                    $response = $this->client()
                        ->get('/calls', $filters);

                    if ($response->successful()) {
                        $data = $response->json();

                        // Cache call lists for 10 seconds (they change frequently)
                        Cache::put($cacheKey, $data, now()->addSeconds(10));

                        return $data;
                    }

                    Log::warning('Failed to list calls from Cloudonix', [
                        'filters' => $filters,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('Exception while listing calls from Cloudonix', [
                        'filters' => $filters,
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: $cacheKey,
            fallbackValue: []
        );
    }

    // =========================================================================
    // Domain Management Methods
    // =========================================================================

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
        try {
            Log::info('Validating Cloudonix domain credentials', [
                'domain_uuid' => $domainUuid,
                'api_key_prefix' => substr($apiKey, 0, 4) . '...',
            ]);

            // Create temporary client with provided credentials
            $tempClient = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->baseUrl($this->baseUrl);

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
     * @param string $domainUuid The domain UUID to update
     * @param string $apiKey The API key (Bearer token) to authenticate with
     * @param array<string, mixed> $profileData Profile settings to update (call-timeout, recording-media-type, etc.)
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function updateDomain(string $domainUuid, string $apiKey, array $profileData): array
    {
        try {
            Log::info('Updating Cloudonix domain profile', [
                'domain_uuid' => $domainUuid,
                'api_key_prefix' => substr($apiKey, 0, 4) . '...',
                'profile_data' => $profileData,
            ]);

            // Create temporary client with provided credentials
            $tempClient = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->baseUrl($this->baseUrl);

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

            if (!$success) {
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

    // =========================================================================
    // Voice Application Management Methods
    // =========================================================================

    /**
     * Create a voice application in Cloudonix.
     *
     * Makes a POST request to /customers/{customer-id}/domains/{domain-id}/applications
     * to create a new CXML voice application for call routing.
     *
     * @param string $domainUuid The domain UUID
     * @param string $apiKey The API key (Bearer token) to authenticate with
     * @param array<string, mixed> $applicationData Application configuration (name, type, url, method, profile)
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function createVoiceApplication(string $domainUuid, string $apiKey, array $applicationData): array
    {
        try {
            Log::info('Creating Cloudonix voice application', [
                'domain_uuid' => $domainUuid,
                'api_key_prefix' => substr($apiKey, 0, 4) . '...',
                'application_name' => $applicationData['name'] ?? null,
            ]);

            // Create temporary client with provided credentials
            $tempClient = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->baseUrl($this->baseUrl);

            $response = $tempClient->post(
                "/customers/{$this->customerId}/domains/{$domainUuid}/applications",
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

            if (!$success) {
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
     * @param string $domainUuid The domain UUID
     * @param string $apiKey The API key (Bearer token) to authenticate with
     * @param int $applicationId The application ID to set as default
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function updateDomainDefaultApplication(string $domainUuid, string $apiKey, int $applicationId): array
    {
        try {
            Log::info('Updating Cloudonix domain default application', [
                'domain_uuid' => $domainUuid,
                'api_key_prefix' => substr($apiKey, 0, 4) . '...',
                'application_id' => $applicationId,
            ]);

            // Create temporary client with provided credentials
            $tempClient = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->baseUrl($this->baseUrl);

            $response = $tempClient->put(
                "/customers/{$this->customerId}/domains/{$domainUuid}",
                ['defaultApplication' => $applicationId]
            );

            $success = $response->successful();
            $responseBody = $response->json();

            Log::info('Cloudonix domain default application update result', [
                'domain_uuid' => $domainUuid,
                'status_code' => $response->status(),
                'success' => $success,
            ]);

            if (!$success) {
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

    // =========================================================================
    // Subscriber Management Methods
    // =========================================================================

    /**
     * Get the base URL for subscriber endpoints.
     *
     * @throws \RuntimeException If domain UUID is not configured
     */
    private function getSubscriberBaseUrl(): string
    {
        if (empty($this->domainUuid)) {
            throw new \RuntimeException(
                'Domain UUID is required for subscriber operations. ' .
                'Please instantiate CloudonixClient with CloudonixSettings.'
            );
        }

        return "/customers/{$this->customerId}/domains/{$this->domainUuid}/subscribers";
    }

    /**
     * Create a new subscriber in Cloudonix.
     *
     * @param string $msisdn Extension number/phone number
     * @param string $sipPassword SIP authentication password
     * @param array<string, mixed>|null $profile Optional profile data
     * @return array<string, mixed>|null Subscriber data or null on failure
     */
    public function createSubscriber(string $msisdn, string $sipPassword, ?array $profile = null): ?array
    {
        try {
            $payload = [
                'msisdn' => $msisdn,
                'sipPassword' => $sipPassword,
            ];

            if ($profile !== null) {
                $payload['profile'] = $profile;
            }

            $url = $this->getSubscriberBaseUrl();

            Log::debug('Cloudonix API request: Create Subscriber', [
                'url' => $this->baseUrl . $url,
                'payload' => [
                    'msisdn' => $msisdn,
                    'sipPassword' => '***', // Masked for security
                    'profile' => $profile,
                ],
            ]);

            $response = $this->client()
                ->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Successfully created Cloudonix subscriber', [
                    'msisdn' => $msisdn,
                    'subscriber_id' => $data['id'] ?? null,
                    'uuid' => $data['uuid'] ?? null,
                    'status' => $response->status(),
                ]);

                return $data;
            }

            Log::error('Failed to create Cloudonix subscriber', [
                'msisdn' => $msisdn,
                'url' => $this->baseUrl . $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => [
                    'msisdn' => $msisdn,
                    'sipPassword' => '***',
                    'profile' => $profile,
                ],
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Exception while creating Cloudonix subscriber', [
                'msisdn' => $msisdn,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Update an existing subscriber in Cloudonix.
     *
     * @param string $subscriberId The Cloudonix subscriber ID
     * @param array<string, mixed> $data Update data (msisdn, sipPassword, profile, etc.)
     * @return array<string, mixed>|null Updated subscriber data or null on failure
     */
    public function updateSubscriber(string $subscriberId, array $data): ?array
    {
        try {
            $url = $this->getSubscriberBaseUrl() . "/{$subscriberId}";

            $response = $this->client()
                ->put($url, $data);

            if ($response->successful()) {
                $responseData = $response->json();
                Log::info('Successfully updated Cloudonix subscriber', [
                    'subscriber_id' => $subscriberId,
                    'updated_fields' => array_keys($data),
                ]);

                return $responseData;
            }

            Log::warning('Failed to update Cloudonix subscriber', [
                'subscriber_id' => $subscriberId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Exception while updating Cloudonix subscriber', [
                'subscriber_id' => $subscriberId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Delete a subscriber from Cloudonix.
     *
     * @param string $subscriberId The Cloudonix subscriber ID
     * @return bool True on success, false on failure
     */
    public function deleteSubscriber(string $subscriberId): bool
    {
        try {
            $url = $this->getSubscriberBaseUrl() . "/{$subscriberId}";

            $response = $this->client()
                ->delete($url);

            if ($response->successful()) {
                Log::info('Successfully deleted Cloudonix subscriber', [
                    'subscriber_id' => $subscriberId,
                ]);

                return true;
            }

            Log::warning('Failed to delete Cloudonix subscriber', [
                'subscriber_id' => $subscriberId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Exception while deleting Cloudonix subscriber', [
                'subscriber_id' => $subscriberId,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get a subscriber from Cloudonix.
     *
     * @param string $subscriberId The Cloudonix subscriber ID
     * @return array<string, mixed>|null Subscriber data or null on failure
     */
    public function getSubscriber(string $subscriberId): ?array
    {
        try {
            $url = $this->getSubscriberBaseUrl() . "/{$subscriberId}";

            $response = $this->client()
                ->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Failed to get Cloudonix subscriber', [
                'subscriber_id' => $subscriberId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Exception while getting Cloudonix subscriber', [
                'subscriber_id' => $subscriberId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * List all subscribers in the domain.
     *
     * @return array<int, array<string, mixed>>|null Array of subscribers or null on failure
     */
    public function listSubscribers(): ?array
    {
        try {
            $url = $this->getSubscriberBaseUrl();

            Log::debug('Cloudonix API request: List Subscribers', [
                'url' => $this->baseUrl . $url,
            ]);

            $response = $this->client()
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Successfully listed Cloudonix subscribers', [
                    'count' => is_array($data) ? count($data) : 0,
                    'status' => $response->status(),
                ]);

                return is_array($data) ? $data : [];
            }

            Log::error('Failed to list Cloudonix subscribers', [
                'url' => $this->baseUrl . $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Exception while listing Cloudonix subscribers', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
