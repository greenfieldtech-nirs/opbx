<?php

declare(strict_types=1);

namespace App\Services\CallNotifications;

use App\Models\CallNotificationLog;
use App\Models\CallNotificationsSettings;
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
     * Dispatch a webhook notification.
     *
     * @param  CallNotificationsSettings  $settings  The notification settings
     * @param  array<string, mixed>  $payload  The webhook payload
     * @param  string  $eventId  Unique event identifier
     * @param  string  $sessionToken  Call session token
     * @return bool Whether the dispatch was successful
     */
    public function dispatch(
        CallNotificationsSettings $settings,
        array $payload,
        string $eventId,
        string $sessionToken
    ): bool {
        // Check rate limiting
        if (! $this->checkRateLimit($settings)) {
            Log::warning('Call notification rate limit exceeded', [
                'organization_id' => $settings->organization_id,
                'event_id' => $eventId,
            ]);

            return false;
        }

        // Create log entry
        $log = CallNotificationLog::create([
            'organization_id' => $settings->organization_id,
            'call_session_token' => $sessionToken,
            'event_id' => $eventId,
            'event_type' => $payload['event_type'] ?? 'call.status_update',
            'status' => $payload['session']['status'] ?? 'unknown',
            'webhook_url' => $settings->webhook_url,
            'request_payload' => $payload,
            'attempt_number' => 1,
            'is_success' => false,
            'created_at' => now(),
        ]);

        // Attempt delivery with retries
        $maxAttempts = $settings->retry_attempts ?? 3;
        $backoffSeconds = $settings->retry_backoff_seconds ?? 60;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = $this->attemptDelivery($settings, $payload, $log);

            if ($result['success']) {
                return true;
            }

            // Check if we should retry
            if ($attempt < $maxAttempts && $this->shouldRetry($result['status_code'])) {
                $delay = $backoffSeconds * pow(2, $attempt - 1);
                Log::info('Retrying call notification webhook', [
                    'organization_id' => $settings->organization_id,
                    'event_id' => $eventId,
                    'attempt' => $attempt,
                    'next_attempt_delay' => $delay,
                ]);
                sleep($delay);

                // Update log for next attempt
                $log->update(['attempt_number' => $attempt + 1]);
            } else {
                // Final failure
                break;
            }
        }

        return false;
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

        try {
            // Get authentication headers
            $headers = $settings->getAuthHeaders($payload);
            $headers['Content-Type'] = 'application/json';
            $headers['Accept'] = 'application/json';
            $headers['User-Agent'] = 'Cloudonix-PBX-Webhook/1.0';

            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->post($settings->webhook_url, $payload);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $log->markAsSuccess(
                    $response->status(),
                    $response->body(),
                    $responseTimeMs
                );

                Log::info('Call notification webhook delivered successfully', [
                    'organization_id' => $settings->organization_id,
                    'event_id' => $log->event_id,
                    'response_time_ms' => $responseTimeMs,
                ]);

                return [
                    'success' => true,
                    'status_code' => $response->status(),
                ];
            }

            // Non-successful response
            $log->markAsFailed(
                $response->status(),
                $response->body(),
                'HTTP error: '.$response->status(),
                $responseTimeMs
            );

            Log::warning('Call notification webhook returned non-success status', [
                'organization_id' => $settings->organization_id,
                'event_id' => $log->event_id,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
            ]);

            return [
                'success' => false,
                'status_code' => $response->status(),
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $log->markAsFailed(
                0,
                null,
                'Connection error: '.$e->getMessage(),
                $responseTimeMs
            );

            Log::error('Call notification webhook connection failed', [
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
                $responseTimeMs
            );

            Log::error('Call notification webhook delivery failed', [
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

        $current = Redis::get($key);

        if ($current === null) {
            // First request in window
            Redis::setex($key, $window, 1);

            return true;
        }

        if ((int) $current >= $limit) {
            return false;
        }

        Redis::incr($key);

        return true;
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

        $settings = CallNotificationsSettings::forOrganization($organizationId)->first();
        $limit = $settings?->rate_limit_per_minute ?? 500;

        return [
            'limit' => $limit,
            'current' => $current,
            'remaining' => max(0, $limit - $current),
            'reset_in_seconds' => max(0, $ttl),
        ];
    }
}
