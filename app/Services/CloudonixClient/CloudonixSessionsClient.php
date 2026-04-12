<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use Illuminate\Support\Facades\Log;

/**
 * Cloudonix API client for session management operations.
 *
 * Handles session retrieval and disconnection operations.
 *
 * @see https://developers.cloudonix.com/cloudonixRestOpenAPI#/operations/deleteSession
 */
class CloudonixSessionsClient extends CloudonixBaseClient
{
    /**
     * Get session details from Cloudonix.
     *
     * Makes a GET request to /customers/self/domains/{domain-id}/sessions/{session-id}
     * to retrieve session information including the session token.
     *
     * @param  int|string  $sessionId  The Cloudonix session ID
     * @return array<string, mixed>|null Session details or null on failure
     */
    public function getSession(int|string $sessionId): ?array
    {
        $this->requireDomainUuid();

        return $this->withCircuitBreaker(
            callback: function () use ($sessionId) {
                try {
                    $url = "/customers/{$this->getCustomerId()}/domains/{$this->getDomainUuid()}/sessions/{$sessionId}";
                    $fullUrl = $this->getBaseUrl().$url;

                    Log::info('CloudonixClient: Fetching session details', [
                        'session_id' => $sessionId,
                        'url' => $fullUrl,
                    ]);

                    $response = $this->client()->get($url);

                    if ($response->successful()) {
                        $data = $response->json();
                        Log::info('CloudonixClient: Session details retrieved', [
                            'session_id' => $sessionId,
                            'has_token' => isset($data['token']),
                        ]);

                        return $data;
                    }

                    Log::warning('CloudonixClient: Failed to get session details', [
                        'session_id' => $sessionId,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('CloudonixClient: Exception while getting session', [
                        'session_id' => $sessionId,
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: null,
            fallbackValue: null
        );
    }

    /**
     * Disconnect a session by session ID.
     *
     * Makes a DELETE request to /customers/self/domains/{domain-id}/sessions/{session-id}
     * to terminate an active session.
     *
     * @see https://developers.cloudonix.com/cloudonixRestOpenAPI#/operations/deleteSession
     *
     * @param  int|string  $sessionId  The Cloudonix session ID
     * @return bool True on success, false on failure
     */
    public function disconnectSession(int|string $sessionId): bool
    {
        $this->requireDomainUuid();

        return $this->withCircuitBreaker(
            callback: function () use ($sessionId) {
                try {
                    // Correct URL format: /customers/self/domains/{domain-id}/sessions/{session-id}
                    $url = "/customers/{$this->getCustomerId()}/domains/{$this->getDomainUuid()}/sessions/{$sessionId}";
                    $fullUrl = $this->getBaseUrl().$url;

                    Log::info('CloudonixClient: Attempting to disconnect session', [
                        'session_id' => $sessionId,
                        'url' => $fullUrl,
                        'method' => 'DELETE',
                        'base_url' => $this->getBaseUrl(),
                        'customer_id' => $this->getCustomerId(),
                        'domain_uuid' => $this->getDomainUuid(),
                    ]);

                    $response = $this->client()
                        ->delete($url);

                    $statusCode = $response->status();
                    $responseBody = $response->body();
                    $isSuccessful = $response->successful();

                    Log::info('CloudonixClient: Disconnect session response', [
                        'session_id' => $sessionId,
                        'url' => $fullUrl,
                        'status_code' => $statusCode,
                        'is_successful' => $isSuccessful,
                        'response_body' => $responseBody,
                        'response_headers' => $response->headers(),
                    ]);

                    if ($isSuccessful) {
                        Log::info('CloudonixClient: Successfully disconnected session', [
                            'session_id' => $sessionId,
                            'status_code' => $statusCode,
                        ]);

                        return true;
                    }

                    // 404 means session doesn't exist (already ended or never existed)
                    // This is not necessarily an error - treat it as success
                    if ($statusCode === 404) {
                        Log::info('CloudonixClient: Session not found (already ended)', [
                            'session_id' => $sessionId,
                            'status_code' => $statusCode,
                        ]);

                        return true;
                    }

                    Log::warning('CloudonixClient: Failed to disconnect session', [
                        'session_id' => $sessionId,
                        'url' => $fullUrl,
                        'status_code' => $statusCode,
                        'response_body' => $responseBody,
                        'is_client_error' => $statusCode >= 400 && $statusCode < 500,
                        'is_server_error' => $statusCode >= 500,
                    ]);

                    return false;
                } catch (\Exception $e) {
                    Log::error('CloudonixClient: Exception while disconnecting session', [
                        'session_id' => $sessionId,
                        'exception_class' => get_class($e),
                        'exception_message' => $e->getMessage(),
                        'exception_file' => $e->getFile(),
                        'exception_line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: null, // No caching for write operations
            fallbackValue: false
        );
    }
}
