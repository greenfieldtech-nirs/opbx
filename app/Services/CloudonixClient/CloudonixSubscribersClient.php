<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use Illuminate\Support\Facades\Log;

/**
 * Cloudonix API client for subscriber management operations.
 *
 * Handles subscriber CRUD operations, extension synchronization,
 * and subscriber listing.
 *
 * @see https://developers.cloudonix.com/cloudonixRestOpenAPI
 */
class CloudonixSubscribersClient extends CloudonixBaseClient
{
    /**
     * Get the base URL for subscriber endpoints.
     *
     * @throws \RuntimeException If domain UUID is not configured
     */
    private function getSubscriberBaseUrl(): string
    {
        $this->requireDomainUuid();

        return "/customers/{$this->getCustomerId()}/domains/{$this->getDomainUuid()}/subscribers";
    }

    /**
     * Create a new subscriber in Cloudonix.
     *
     * @param  string  $msisdn  Extension number/phone number
     * @param  string  $sipPassword  SIP authentication password
     * @param  array<string, mixed>|null  $profile  Optional profile data
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
                'url' => $this->getBaseUrl().$url,
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
                'url' => $this->getBaseUrl().$url,
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
     * @param  string  $subscriberId  The Cloudonix subscriber ID
     * @param  array<string, mixed>  $data  Update data (msisdn, sipPassword, profile, etc.)
     * @return array<string, mixed>|null Updated subscriber data or null on failure
     */
    public function updateSubscriber(string $subscriberId, array $data): ?array
    {
        try {
            $url = $this->getSubscriberBaseUrl()."/{$subscriberId}";

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
     * @param  string  $subscriberId  The Cloudonix subscriber ID
     * @return bool True on success, false on failure
     */
    public function deleteSubscriber(string $subscriberId): bool
    {
        try {
            $url = $this->getSubscriberBaseUrl()."/{$subscriberId}";

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
     * @param  string  $subscriberId  The Cloudonix subscriber ID
     * @return array<string, mixed>|null Subscriber data or null on failure
     */
    public function getSubscriber(string $subscriberId): ?array
    {
        try {
            $url = $this->getSubscriberBaseUrl()."/{$subscriberId}";

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
                'url' => $this->getBaseUrl().$url,
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
                'url' => $this->getBaseUrl().$url,
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

    /**
     * Get available voices for the domain.
     *
     * @throws \RuntimeException
     */
    public function getVoices(string $domainUuid): array
    {
        return $this->circuitBreaker->call(function () use ($domainUuid) {
            $url = "/domains/{$domainUuid}/resources/voices";
            $startTime = microtime(true);

            Log::info('Cloudonix API: Fetching voices', [
                'domain_uuid' => $domainUuid,
                'url' => $this->getBaseUrl().$url,
                'method' => 'GET',
            ]);

            try {
                $response = $this->client()->get($url);
                $endTime = microtime(true);
                $duration = round(($endTime - $startTime) * 1000, 2); // milliseconds

                $statusCode = $response->status();
                $responseBody = $response->body();
                $responseHeaders = $response->headers();

                Log::info('Cloudonix API: Voices fetch completed', [
                    'domain_uuid' => $domainUuid,
                    'url' => $this->getBaseUrl().$url,
                    'status_code' => $statusCode,
                    'duration_ms' => $duration,
                    'response_size_bytes' => strlen($responseBody),
                    'content_type' => $responseHeaders['Content-Type'][0] ?? 'unknown',
                ]);

                if ($response->failed()) {
                    Log::error('Cloudonix API: Failed to fetch voices - detailed error', [
                        'domain_uuid' => $domainUuid,
                        'url' => $this->getBaseUrl().$url,
                        'method' => 'GET',
                        'status_code' => $statusCode,
                        'duration_ms' => $duration,
                        'response_headers' => $responseHeaders,
                        'response_body' => $responseBody,
                        'response_size_bytes' => strlen($responseBody),
                        'is_json' => $response->header('Content-Type') === 'application/json',
                        'json_parseable' => $this->isValidJson($responseBody),
                        'error_summary' => $this->extractErrorSummary($responseBody, $statusCode),
                    ]);

                    throw new \RuntimeException(
                        sprintf('Failed to fetch voices from Cloudonix API: HTTP %d - %s',
                            $statusCode,
                            $this->extractErrorMessage($responseBody, $statusCode)
                        )
                    );
                }

                // Log success with summary
                $voicesData = $response->json();
                $voicesCount = is_array($voicesData) ? count($voicesData) : 0;

                Log::info('Cloudonix API: Voices fetch successful', [
                    'domain_uuid' => $domainUuid,
                    'voices_count' => $voicesCount,
                    'duration_ms' => $duration,
                ]);

                return $voicesData;
            } catch (\Exception $e) {
                $endTime = microtime(true);
                $duration = round(($endTime - $startTime) * 1000, 2);

                Log::error('Cloudonix API: Exception during voices fetch', [
                    'domain_uuid' => $domainUuid,
                    'url' => $this->getBaseUrl().$url,
                    'duration_ms' => $duration,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                ]);

                throw $e;
            }
        });
    }
}
