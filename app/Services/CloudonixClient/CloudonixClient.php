<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

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

    public function __construct()
    {
        $this->baseUrl = rtrim(config('cloudonix.api.base_url'), '/');
        $this->token = config('cloudonix.api.token');
        $this->timeout = config('cloudonix.api.timeout', 30);

        if (empty($this->token)) {
            throw new \RuntimeException('Cloudonix API token is not configured');
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
}
