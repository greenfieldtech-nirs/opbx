<?php

declare(strict_types=1);

namespace App\Services\CallTracking\Adapters;

use App\Models\CallTrackingSession;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Adapter for uploading call conversions to Google Ads.
 *
 * Uses the Google Ads API REST endpoint for call conversions. The stored
 * `google_ads_refresh_token` is used as the Bearer access token. In production
 * you may want to extend this to perform an OAuth2 refresh before each call.
 *
 * @see https://developers.google.com/google-ads/api/docs/conversions/upload-call-conversions
 */
class GoogleAdsConversionUploadService
{
    private const API_VERSION = 'v16';

    /**
     * Upload a call conversion for the given session.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function uploadCallConversion(CallTrackingSession $session, array $config): array
    {
        $this->validateConfig($config);

        $payload = $this->buildPayload($session, $config['conversion_action_resource_name']);

        $url = sprintf(
            'https://googleads.googleapis.com/%s/customers/%s:uploadCallConversions',
            self::API_VERSION,
            $this->normalizeCustomerId($config['customer_id'])
        );

        $requestHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.((string) $config['refresh_token']),
            'developer-token' => (string) $config['developer_token'],
            'login-customer-id' => $this->normalizeCustomerId($config['customer_id']),
        ];

        $response = Http::timeout(30)
            ->withHeaders($requestHeaders)
            ->post($url, $payload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Google Ads API error: HTTP %d - %s',
                $response->status(),
                $response->body()
            ));
        }

        return [
            'status' => 'success',
            'provider' => 'google_ads',
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
        $required = ['developer_token', 'customer_id', 'conversion_action_resource_name', 'refresh_token'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException("Missing required config key: {$key}");
            }
        }
    }

    /**
     * Build the Google Ads API request payload.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(CallTrackingSession $session, string $conversionActionResourceName): array
    {
        $conversion = [
            'callerId' => $session->caller_number,
            'callStartDateTime' => $this->formatDateTime($session->started_at),
            'conversionAction' => $conversionActionResourceName,
            'conversionDateTime' => $this->formatDateTime($session->ended_at ?? $session->started_at),
        ];

        if ($session->conversion_value !== null) {
            $conversion['conversionValue'] = (float) $session->conversion_value;
            $conversion['currencyCode'] = 'USD';
        }

        return [
            'operations' => [
                ['create' => $conversion],
            ],
            'partialFailure' => true,
        ];
    }

    /**
     * Format a datetime as RFC 3339 / ISO 8601.
     */
    private function formatDateTime(?\DateTimeInterface $dateTime): string
    {
        return ($dateTime ?? now())->format('Y-m-d\\TH:i:s\\Z');
    }

    /**
     * Remove dashes from the customer ID to match the API path format.
     */
    private function normalizeCustomerId(string $customerId): string
    {
        return str_replace('-', '', $customerId);
    }
}
