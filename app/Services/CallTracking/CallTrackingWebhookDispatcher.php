<?php

declare(strict_types=1);

namespace App\Services\CallTracking;

use App\Models\CallTrackingNotificationLog;
use App\Models\CallTrackingNotificationSettings;
use App\Models\CallTrackingSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class CallTrackingWebhookDispatcher
{
    public function __construct(
        private readonly NotificationPayloadBuilder $payloadBuilder,
    ) {}

    public function dispatch(
        CallTrackingNotificationSettings $settings,
        CallTrackingSession $session,
        string $eventType,
        string $eventId,
    ): CallTrackingNotificationLog {
        $webhookUrl = (string) $settings->webhook_url;

        if (! $this->isValidUrl($webhookUrl)) {
            return $this->persistFailure(
                settings: $settings,
                session: $session,
                eventType: $eventType,
                eventId: $eventId,
                webhookUrl: $webhookUrl,
                requestPayload: [],
                requestHeaders: [],
                errorMessage: 'Invalid or unsafe webhook URL.',
            );
        }

        $payload = $this->payloadBuilder->build($session, $eventType, $eventId);

        $requestHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $http = Http::timeout(30)
            ->withHeaders($requestHeaders);

        match ($settings->auth_method) {
            'bearer_token' => $http = $http->withToken((string) $settings->auth_secret),
            'basic_auth' => $http = $http->withBasicAuth(
                (string) $settings->auth_username,
                (string) $settings->auth_secret,
            ),
            default => null,
        };

        $requestHeaders = $this->buildRequestHeaders($settings, $requestHeaders);

        $startedAt = microtime(true);

        try {
            $response = $http->post($webhookUrl, $payload);
        } catch (ConnectionException $exception) {
            return $this->persistFailure(
                settings: $settings,
                session: $session,
                eventType: $eventType,
                eventId: $eventId,
                webhookUrl: $webhookUrl,
                requestPayload: $payload,
                requestHeaders: $requestHeaders,
                errorMessage: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            return $this->persistFailure(
                settings: $settings,
                session: $session,
                eventType: $eventType,
                eventId: $eventId,
                webhookUrl: $webhookUrl,
                requestPayload: $payload,
                requestHeaders: $requestHeaders,
                errorMessage: $exception->getMessage(),
            );
        }

        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        return CallTrackingNotificationLog::create([
            'organization_id' => $settings->organization_id,
            'call_tracking_campaign_id' => $settings->call_tracking_campaign_id,
            'call_id' => $session->call_id,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'webhook_url' => $webhookUrl,
            'request_payload' => $payload,
            'request_headers' => $requestHeaders,
            'response_body' => $response->body(),
            'response_headers' => $response->headers(),
            'response_status_code' => $response->status(),
            'response_time_ms' => $responseTimeMs,
            'is_success' => $response->successful(),
            'attempt_number' => 1,
            'error_message' => $response->successful() ? null : "HTTP {$response->status()}",
        ]);
    }

    private function isValidUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return false;
        }

        $lowerHost = strtolower($host);

        if (
            $lowerHost === 'localhost'
            || str_ends_with($lowerHost, '.localhost')
            || str_ends_with($lowerHost, '.local')
        ) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);

        if ($ip !== false) {
            return $this->isPublicIp($ip);
        }

        $resolved = gethostbyname($host);

        if ($resolved === $host) {
            // Could not resolve; still allow HTTP to fail naturally but do not allow obvious loopback names
            return ! in_array($lowerHost, ['127.0.0.1', '::1'], true);
        }

        return $this->isPublicIp($resolved);
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @param  array<string, string>  $baseHeaders
     * @return array<string, string>
     */
    private function buildRequestHeaders(
        CallTrackingNotificationSettings $settings,
        array $baseHeaders,
    ): array {
        return match ($settings->auth_method) {
            'bearer_token' => [
                ...$baseHeaders,
                'Authorization' => 'Bearer '.((string) $settings->auth_secret),
            ],
            'basic_auth' => [
                ...$baseHeaders,
                'Authorization' => 'Basic '.base64_encode(((string) $settings->auth_username).':'.((string) $settings->auth_secret)),
            ],
            default => $baseHeaders,
        };
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, string>  $requestHeaders
     */
    private function persistFailure(
        CallTrackingNotificationSettings $settings,
        CallTrackingSession $session,
        string $eventType,
        string $eventId,
        string $webhookUrl,
        array $requestPayload,
        array $requestHeaders,
        string $errorMessage,
    ): CallTrackingNotificationLog {
        return CallTrackingNotificationLog::create([
            'organization_id' => $settings->organization_id,
            'call_tracking_campaign_id' => $settings->call_tracking_campaign_id,
            'call_id' => $session->call_id,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'webhook_url' => $webhookUrl,
            'request_payload' => $requestPayload,
            'request_headers' => $requestHeaders,
            'response_body' => null,
            'response_headers' => null,
            'response_status_code' => null,
            'response_time_ms' => null,
            'is_success' => false,
            'attempt_number' => 1,
            'error_message' => $errorMessage,
        ]);
    }
}
