<?php

declare(strict_types=1);

namespace App\Services\CallTracking\Adapters;

use App\Models\CallTrackingSession;
use InvalidArgumentException;

/**
 * Stub adapter for uploading call conversions to Google Ads.
 */
class GoogleAdsConversionUploadService
{
    /**
     * Upload a call conversion for the given session.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function uploadCallConversion(CallTrackingSession $session, array $config): array
    {
        $this->validateConfig($config);

        return [
            'status' => 'stub',
            'message' => 'Google Ads upload not implemented in v1',
            'session_id' => $session->id,
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
        $required = ['developer_token', 'customer_id', 'conversion_action_resource_name'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException("Missing required config key: {$key}");
            }
        }
    }
}
