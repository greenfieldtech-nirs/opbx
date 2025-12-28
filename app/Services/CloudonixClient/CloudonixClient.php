<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

    /**
     * Create a new CloudonixClient instance.
     *
     * @param CloudonixSettings|Organization|null $settings Organization settings or Organization model
     * @throws \RuntimeException If API token is not configured
     */
    public function __construct(CloudonixSettings|Organization|null $settings = null)
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

            if (empty($this->token) || empty($this->domainUuid)) {
                throw new \RuntimeException(
                    'Organization Cloudonix settings are not properly configured. ' .
                    'Both domain_api_key and domain_uuid are required. ' .
                    'Please configure them in the Settings page.'
                );
            }
        } else {
            // Fall back to global config for backward compatibility (call management)
            $this->token = config('cloudonix.api.token');
            $this->domainUuid = null;

            if (empty($this->token)) {
                throw new \RuntimeException('Cloudonix API token is not configured');
            }
        }
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
     * Get call status by call ID.
     *
     * @param string $callId The Cloudonix call ID
     * @return array<string, mixed>|null
     */
    public function getCallStatus(string $callId): ?array
    {
        try {
            $response = $this->client()
                ->get("/calls/{$callId}");

            if ($response->successful()) {
                return $response->json();
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

            return null;
        }
    }

    /**
     * Get CDR (Call Detail Record) by call ID.
     *
     * @param string $callId The Cloudonix call ID
     * @return array<string, mixed>|null
     */
    public function getCallCdr(string $callId): ?array
    {
        try {
            $response = $this->client()
                ->get("/calls/{$callId}/cdr");

            if ($response->successful()) {
                return $response->json();
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

            return null;
        }
    }

    /**
     * Hangup a call.
     *
     * @param string $callId The Cloudonix call ID
     */
    public function hangupCall(string $callId): bool
    {
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

            return false;
        }
    }

    /**
     * Get list of calls with optional filters.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function listCalls(array $filters = []): ?array
    {
        try {
            $response = $this->client()
                ->get('/calls', $filters);

            if ($response->successful()) {
                return $response->json();
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

            return null;
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
