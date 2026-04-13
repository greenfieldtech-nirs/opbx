<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\CircuitBreaker\CircuitBreakerOpenException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Base HTTP client for Cloudonix REST API.
 *
 * Provides common HTTP functionality, authentication, circuit breaker protection,
 * and error handling for all Cloudonix API clients.
 *
 * @see https://developers.cloudonix.com/Documentation/core-api
 * @see https://developers.cloudonix.com/cloudonixRestOpenAPI
 */
abstract class CloudonixBaseClient
{
    protected string $baseUrl;

    protected string $token;

    protected int $timeout;

    protected ?string $domainUuid;

    protected string $customerId;

    protected CircuitBreaker $circuitBreaker;

    /**
     * Create a new CloudonixBaseClient instance.
     *
     * @param  CloudonixSettings|Organization|null  $settings  Organization settings or Organization model
     * @param  bool  $requireCredentials  Whether to require credentials at instantiation (default: true)
     *
     * @throws \RuntimeException If API token is not configured and credentials are required
     */
    public function __construct(CloudonixSettings|Organization|null $settings = null, bool $requireCredentials = true)
    {
        // Validate base URL configuration
        $baseUrl = config('cloudonix.api.base_url');
        if (empty($baseUrl)) {
            throw new \RuntimeException(
                'Cloudonix API base URL is not configured. '.
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
                    'Organization Cloudonix settings are not properly configured. '.
                    'Both domain_api_key and domain_uuid are required. '.
                    'Please configure them in the Settings page.'
                );
            }
        } else {
            // No CloudonixSettings provided - credentials must be passed explicitly
            $this->token = '';
            $this->domainUuid = null;

            if ($requireCredentials) {
                throw new \RuntimeException(
                    'CloudonixClient requires CloudonixSettings or explicit credentials. '.
                    'Please instantiate with an Organization or CloudonixSettings model.'
                );
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
                'Authorization' => 'Bearer '.$this->token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->baseUrl($this->baseUrl);
    }

    /**
     * Execute an API call with circuit breaker protection.
     *
     * @param  callable  $callback  The API call to execute
     * @param  string|null  $cacheKey  Optional cache key for fallback data
     * @param  mixed  $fallbackValue  Fallback value if circuit is open and no cache
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
     * Get the base URL for API requests.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the domain UUID.
     */
    public function getDomainUuid(): ?string
    {
        return $this->domainUuid;
    }

    /**
     * Check if a string is valid JSON.
     */
    protected function isValidJson(string $json): bool
    {
        json_decode($json);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Extract a brief error summary from response body.
     */
    protected function extractErrorSummary(string $body, int $statusCode): string
    {
        if ($this->isValidJson($body)) {
            $data = json_decode($body, true);
            if (isset($data['message'])) {
                return $data['message'];
            }
            if (isset($data['error'])) {
                return $data['error'];
            }
        }

        // Common HTTP status messages
        $statusMessages = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];

        return $statusMessages[$statusCode] ?? 'Unknown Error';
    }

    /**
     * Extract error message from response body.
     */
    protected function extractErrorMessage(string $body, int $statusCode): string
    {
        $summary = $this->extractErrorSummary($body, $statusCode);

        if (strlen($body) > 100) {
            return $summary.' (response body truncated)';
        }

        return $summary;
    }

    /**
     * Validate that domain UUID is configured for operations that require it.
     *
     * @throws \RuntimeException If domain UUID is not configured
     */
    protected function requireDomainUuid(): void
    {
        if (empty($this->domainUuid)) {
            throw new \RuntimeException(
                'Domain UUID is required for this operation. '.
                'Please instantiate the client with CloudonixSettings.'
            );
        }
    }

    /**
     * Get the customer ID.
     */
    protected function getCustomerId(): string
    {
        return $this->customerId;
    }

    /**
     * Create a temporary HTTP client with explicit credentials.
     *
     * Used for operations that need different credentials than the instance ones.
     */
    protected function createTemporaryClient(string $apiKey): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->baseUrl($this->baseUrl);
    }
}
