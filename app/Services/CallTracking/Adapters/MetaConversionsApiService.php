<?php

declare(strict_types=1);

namespace App\Services\CallTracking\Adapters;

use App\Models\CallTrackingSession;
use InvalidArgumentException;

/**
 * Stub adapter for sending offline events to the Meta Conversions API.
 */
class MetaConversionsApiService
{
    /**
     * Send an offline conversion event for the given session.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function sendOfflineEvent(CallTrackingSession $session, array $config): array
    {
        $this->validateConfig($config);

        return [
            'status' => 'stub',
            'message' => 'Meta Conversions API not implemented in v1',
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
        $required = ['pixel_id', 'access_token'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException("Missing required config key: {$key}");
            }
        }
    }
}
