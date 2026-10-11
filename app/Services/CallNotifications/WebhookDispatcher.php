<?php

declare(strict_types=1);

namespace App\Services\CallNotifications;

use App\Models\CallNotificationLog;
use App\Models\CallNotificationsSettings;
use App\Scopes\OrganizationScope;
use App\Services\Security\SsrfUrlValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Webhook Dispatcher Service
 *
 * Handles dispatching call notification webhooks with retry logic,
 * rate limiting, and comprehensive logging.
 */
class WebhookDispatcher
{
    /**
     * Rate limit key prefix for Redis.
     */
    private const RATE_LIMIT_KEY_PREFIX = 'call_notifications:rate_limit:';

    /**
     * Deliver a webhook notification (single attempt, no retries).
     *
     * @param  array<string, mixed>  $payload  The webhook payload
     * @return bool Whether the delivery was successful
     */
    public function dispatch(
        CallNotificationsSettings $settings,
        array $payload,
        string $eventId,
        string $sessionToken
    ): bool {
        return $this->deliver($settings, $payload, $eventId, $sessionToken)['delivered'];
    }

    /**
     * Make one delivery attempt and report whether a later attempt could succeed.
     * Retries are the caller's job (see DispatchCallNotificationJob): this never sleeps.
     *
     * @param  array<string, mixed>  $payload
     * @param  int  $attempt  1-based attempt number; the rate limit is only charged on the first
     * @return array{delivered: bool, retryable: bool}
     */
    public function deliver(
        CallNotificationsSettings $settings,
        array $payload,
        string $eventId,
        string $sessionToken,
        int $attempt = 1
    ): array {
        // Defense in depth: Validate URL against SSRF attacks before dispatching
        $ssrfValidator = new SsrfUrlValidator;
        $isValidUrl = $ssrfValidator->isValid($settings->webhook_url);

        Log::debug('SSRF validation check', [
            'organization_id' => $settings->organization_id,
            'webhook_url' => $settings->webhook_url,
            'is_valid' => $isValidUrl,
        ]);

        if (! $isValidUrl) {
            Log::warning('Blocked webhook dispatch to internal URL', [
                'organization_id' => $settings->organization_id,
                'event_id' => $eventId,
                'webhook_url' => $settings->webhook_url,
            ]);

            return ['delivered' => false, 'retryable' => false];
        }

        if ($attempt === 1 && ! $this->checkRateLimit($settings)) {
            Log::warning('Call notification rate limit exceeded', [
                'organization_id' => $settings->organization_id,
                'event_id' => $eventId,
            ]);

            return ['delivered' => false, 'retryable' => false];
        }

        // Must bypass OrganizationScope since webhooks have no authenticated user context.
        // One log row per event: later attempts update it.
        $log = OrganizationScope::bypass(function () use ($settings, $payload, $eventId, $sessionToken, $attempt) {
            $log = $attempt > 1
                ? CallNotificationLog::where('organization_id', $settings->organization_id)->where('event_id', $eventId)->first()
                : null;

            if ($log) {
                $log->update(['attempt_number' => $attempt]);

                return $log;
            }

            return CallNotificationLog::create([
                'organization_id' => $settings->organization_id,
                'call_session_token' => $sessionToken,
                'event_id' => $eventId,
                'event_type' => $payload['event_type'] ?? 'call.status_update',
                'status' => $payload['session']['status'] ?? 'unknown',
                'webhook_url' => $settings->webhook_url,
                'request_payload' => $payload,
                'attempt_number' => $attempt,
                'is_success' => false,
                'created_at' => now(),
            ]);
        });

        $result = $this->attemptDelivery($settings, $payload, $log);

        return [
            'delivered' => $result['success'],
            'retryable' => ! $result['success'] && $this->shouldRetry($result['status_code']),
        ];
    }

    /**
     * Attempt a single webhook delivery.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attemptDelivery(
        CallNotificationsSettings $settings,
        array $payload,
        CallNotificationLog $log
    ): array {
        $startTime = microtime(true);
        $timeout = $settings->request_timeout_seconds ?? 30;

        // Prepare request details for debug logging
        $headers = $settings->getAuthHeaders($payload);
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';
        $headers['User-Agent'] = 'Cloudonix-PBX-Webhook/1.0';
        // Delivery logs must never hold the customer's webhook secret.
        $loggedHeaders = isset($headers['Authorization']) ? ['Authorization' => '[redacted]'] + $headers : $headers;

        $requestBody = json_encode($payload);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->post($settings->webhook_url, $payload);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Capture debug info
            $debugInfo = [
                'request_headers' => $loggedHeaders,
                'request_body' => $requestBody,
                'response_headers' => $response->headers(),
            ];

            if ($response->successful()) {
                $log->markAsSuccess(
                    $response->status(),
                    $response->body(),
                    $responseTimeMs,
                    $debugInfo
                );

                Log::info('Call notification webhook delivered successfully', [
                    'type' => 'HTTP_RESPONSE',
                    'organization_id' => $settings->organization_id,
                    'event_id' => $log->event_id,
                    'status' => $response->status(),
                    'response_time_ms' => $responseTimeMs,
                ]);

                return [
                    'success' => true,
                    'status_code' => $response->status(),
                ];
            }

            // Non-successful response
            $debugInfo['response_headers'] = $response->headers();
            $log->markAsFailed(
                $response->status(),
                $response->body(),
                'HTTP error: '.$response->status(),
                $responseTimeMs,
                $debugInfo
            );

            Log::warning('Call notification webhook returned non-success status', [
                'type' => 'HTTP_RESPONSE',
                'organization_id' => $settings->organization_id,
                'event_id' => $log->event_id,
                'status' => $response->status(),
                'response_body' => $response->body(),
            ]);

            return [
                'success' => false,
                'status_code' => $response->status(),
            ];

        } catch (ConnectionException $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $log->markAsFailed(
                0,
                null,
                'Connection error: '.$e->getMessage(),
                $responseTimeMs,
                [
                    'request_headers' => $loggedHeaders,
                    'request_body' => $requestBody,
                    'response_headers' => null,
                ]
            );

            Log::error('Call notification webhook connection failed', [
                'type' => 'HTTP_REQUEST',
                'organization_id' => $settings->organization_id,
                'event_id' => $log->event_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status_code' => 0,
            ];

        } catch (\Exception $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $log->markAsFailed(
                0,
                null,
                'Exception: '.$e->getMessage(),
                $responseTimeMs,
                [
                    'request_headers' => $loggedHeaders,
                    'request_body' => $requestBody,
                    'response_headers' => null,
                ]
            );

            Log::error('Call notification webhook delivery failed', [
                'type' => 'HTTP_REQUEST',
                'organization_id' => $settings->organization_id,
                'event_id' => $log->event_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status_code' => 0,
            ];
        }
    }

    /**
     * Check if delivery should be retried based on status code.
     */
    private function shouldRetry(int $statusCode): bool
    {
        // Retry on server errors (5xx) and specific client errors
        return $statusCode >= 500 || $statusCode === 0 || $statusCode === 429;
    }

    /**
     * Check rate limit for organization.
     */
    private function checkRateLimit(CallNotificationsSettings $settings): bool
    {
        $key = self::RATE_LIMIT_KEY_PREFIX.$settings->organization_id;
        $limit = $settings->rate_limit_per_minute ?? 500;
        $window = 60; // 1 minute

        // Atomic: INCR then set the TTL only when this call created the key.
        $current = (int) Redis::incr($key);
        if ($current === 1) {
            Redis::expire($key, $window);
        }

        return $current <= $limit;
    }

    /**
     * Get current rate limit status for organization.
     *
     * @return array<string, mixed>
     */
    public function getRateLimitStatus(int $organizationId): array
    {
        $key = self::RATE_LIMIT_KEY_PREFIX.$organizationId;
        $current = (int) (Redis::get($key) ?? 0);
        $ttl = Redis::ttl($key);

        $settings = OrganizationScope::bypass(function () use ($organizationId) {
            return CallNotificationsSettings::forOrganization($organizationId)->first();
        });
        $limit = $settings?->rate_limit_per_minute ?? 500;

        return [
            'limit' => $limit,
            'current' => $current,
            'remaining' => max(0, $limit - $current),
            'reset_in_seconds' => max(0, $ttl),
        ];
    }
}
