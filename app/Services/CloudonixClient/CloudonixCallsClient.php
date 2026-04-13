<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cloudonix API client for call management operations.
 *
 * Handles call initiation, status retrieval, CDR fetching, and call controls
 * like hangup operations.
 *
 * @see https://developers.cloudonix.com/cloudonixRestOpenAPI#/operations/initiateCall
 */
class CloudonixCallsClient extends CloudonixBaseClient
{
    /**
     * Get call status by call ID.
     *
     * @param  string  $callId  The Cloudonix call ID
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
     * @param  string  $callId  The Cloudonix call ID
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
     * @param  string  $callId  The Cloudonix call ID
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
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function listCalls(array $filters = []): ?array
    {
        $cacheKey = 'cloudonix:calls:'.md5(json_encode($filters));

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

    /**
     * Initiate an outbound call via Cloudonix API.
     *
     * Makes a POST request to /calls to initiate an outbound call.
     *
     * @see https://developers.cloudonix.com/cloudonixRestOpenAPI#/operations/initiateCall
     *
     * @param  string  $from  Caller ID (E.164 format)
     * @param  string  $to  Destination number (E.164 format)
     * @param  string  $trunk  Outbound trunk name
     * @param  array<string, mixed>  $options  Optional parameters:
     *                                         - timeout: int (1-300 seconds)
     *                                         - execute: string ('connected' or 'immediately')
     *                                         - timeLimit: int (30-14400 seconds)
     *                                         - recording: bool
     *                                         - recordingStatusCallback: string
     *                                         - recordingStatusCallbackEvent: string
     *                                         - trim: string
     *                                         - machineDetection: string ('Enabled' or 'DetectMessageEnd')
     *                                         - machineDetectionTimeout: int (5-120 seconds)
     *                                         - machineDetectionSpeechThreshold: int (500-5000 ms)
     *                                         - machineDetectionSpeechEndThreshold: int (500-5000 ms)
     *                                         - machineDetectionSilenceTimeout: int (500-10000 ms)
     * @return array<string, mixed>|null Call session data or null on failure
     */
    public function initiateCall(string $from, string $to, string $trunk, array $options = []): ?array
    {
        return $this->withCircuitBreaker(
            callback: function () use ($from, $to, $trunk, $options) {
                try {
                    $payload = [
                        'from' => $from,
                        'to' => $to,
                        'trunk' => $trunk,
                    ];

                    // Add optional parameters
                    if (isset($options['timeout'])) {
                        $payload['timeout'] = $options['timeout'];
                    }

                    if (isset($options['execute'])) {
                        $payload['execute'] = $options['execute'];
                    }

                    if (isset($options['timeLimit'])) {
                        $payload['timeLimit'] = $options['timeLimit'];
                    }

                    if (isset($options['recording'])) {
                        $payload['recording'] = $options['recording'];

                        if (isset($options['recordingStatusCallback'])) {
                            $payload['recordingStatusCallback'] = $options['recordingStatusCallback'];
                        }

                        if (isset($options['recordingStatusCallbackEvent'])) {
                            $payload['recordingStatusCallbackEvent'] = $options['recordingStatusCallbackEvent'];
                        }

                        if (isset($options['trim'])) {
                            $payload['trim'] = $options['trim'];
                        }
                    }

                    // Answering Machine Detection
                    if (isset($options['machineDetection'])) {
                        $payload['machineDetection'] = $options['machineDetection'];

                        if (isset($options['machineDetectionTimeout'])) {
                            $payload['machineDetectionTimeout'] = $options['machineDetectionTimeout'];
                        }

                        if (isset($options['machineDetectionSpeechThreshold'])) {
                            $payload['machineDetectionSpeechThreshold'] = $options['machineDetectionSpeechThreshold'];
                        }

                        if (isset($options['machineDetectionSpeechEndThreshold'])) {
                            $payload['machineDetectionSpeechEndThreshold'] = $options['machineDetectionSpeechEndThreshold'];
                        }

                        if (isset($options['machineDetectionSilenceTimeout'])) {
                            $payload['machineDetectionSilenceTimeout'] = $options['machineDetectionSilenceTimeout'];
                        }
                    }

                    Log::info('Initiating outbound call via Cloudonix', [
                        'from' => $from,
                        'to' => substr($to, 0, 8).'...', // Mask for privacy
                        'trunk' => $trunk,
                        'has_amd' => isset($options['machineDetection']),
                        'has_recording' => isset($options['recording']),
                    ]);

                    $response = $this->client()
                        ->post('/calls', $payload);

                    if ($response->successful()) {
                        $data = $response->json();

                        Log::info('Successfully initiated outbound call', [
                            'from' => $from,
                            'to' => substr($to, 0, 8).'...',
                            'call_id' => $data['callId'] ?? null,
                            'session_token' => $data['sessionToken'] ?? null,
                        ]);

                        return $data;
                    }

                    Log::warning('Failed to initiate outbound call', [
                        'from' => $from,
                        'to' => substr($to, 0, 8).'...',
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('Exception while initiating outbound call', [
                        'from' => $from,
                        'to' => substr($to, 0, 8).'...',
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: null, // No caching for call initiation
            fallbackValue: null
        );
    }
}
