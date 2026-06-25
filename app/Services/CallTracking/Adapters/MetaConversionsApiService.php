<?php

declare(strict_types=1);

namespace App\Services\CallTracking\Adapters;

use App\Models\CallTrackingSession;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Adapter for sending offline conversion events to the Meta Conversions API.
 *
 * @see https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api
 */
class MetaConversionsApiService
{
    private const API_VERSION = 'v18.0';

    private const EVENT_NAME = 'Contact';

    private const ACTION_SOURCE = 'phone_call';

    /**
     * Send an offline conversion event for the given session.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function sendOfflineEvent(CallTrackingSession $session, array $config): array
    {
        $this->validateConfig($config);

        $payload = $this->buildPayload($session);

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events',
            self::API_VERSION,
            $config['pixel_id']
        );

        $requestHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $response = Http::timeout(30)
            ->withQueryParameters(['access_token' => $config['access_token']])
            ->withHeaders($requestHeaders)
            ->post($url, $payload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Meta Conversions API error: HTTP %d - %s',
                $response->status(),
                $response->body()
            ));
        }

        return [
            'status' => 'success',
            'provider' => 'meta',
            'session_id' => $session->id,
            'request_payload' => $payload,
            'request_headers' => $requestHeaders,
            'response_status' => $response->status(),
            'response_body' => $response->json(),
        ];
    }

    /**
     * Validate the adapter configuration.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException
     */
    private function validateConfig(array $config): void
    {
        $required = ['pixel_id', 'access_token'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException("Missing required config key: {$key}");
            }
        }
    }

    /**
     * Build the Conversions API payload.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(CallTrackingSession $session): array
    {
        $eventTime = $session->started_at?->getTimestamp() ?? now()->getTimestamp();

        $userData = [
            'ph' => $this->hashPhoneNumber($session->caller_number),
        ];

        $customData = [
            'campaign_id' => (string) $session->call_tracking_campaign_id,
            'campaign_name' => $session->campaign_name ?? '',
            'duration' => $session->duration,
            'billsec' => $session->billsec,
            'disposition' => $session->disposition,
        ];

        if ($session->conversion_value !== null) {
            $customData['value'] = (float) $session->conversion_value;
            $customData['currency'] = 'USD';
        }

        return [
            'data' => [
                [
                    'event_name' => self::EVENT_NAME,
                    'event_time' => $eventTime,
                    'action_source' => self::ACTION_SOURCE,
                    'user_data' => $userData,
                    'custom_data' => $customData,
                ],
            ],
        ];
    }

    /**
     * Normalize and SHA-256 hash a phone number for Meta's Conversions API.
     */
    private function hashPhoneNumber(?string $phoneNumber): string
    {
        if ($phoneNumber === null) {
            return '';
        }

        $normalized = preg_replace('/\D/', '', $phoneNumber) ?? '';

        return hash('sha256', $normalized);
    }
}
