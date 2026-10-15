<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use App\Models\CloudonixSettings;
use Illuminate\Support\Str;

/**
 * Pushes an organization's settings to its Cloudonix domain profile and
 * provisions the webhook authorization key. Shared by SettingsController and
 * the cloudonix:provision-webhook-keys command.
 */
class CloudonixProfileSync
{
    /**
     * Push local settings (timeouts, endpoints, webhook key) to the Cloudonix domain profile.
     *
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function push(CloudonixSettings $settings): array
    {
        $profileData = [
            'call-timeout' => $settings->no_answer_timeout,
            'recording-media-type' => $settings->recording_format,
        ];

        if ($callbackUrl = $settings->getCallbackUrl()) {
            $profileData['session-update-endpoint'] = $callbackUrl;
        }

        if ($settings->domain_requests_api_key) {
            $profileData['authorization-api-key'] = $settings->domain_requests_api_key;
        }

        if ($cdrUrl = $settings->getCdrUrl()) {
            $profileData['cdr-endpoint'] = $cdrUrl;
        }

        return (new CloudonixClient($settings))->updateDomain(
            $settings->domain_uuid,
            $settings->domain_api_key,
            $profileData
        );
    }

    /**
     * Generate a webhook key, store it and push it. The key is rolled back if the
     * push fails, so Cloudonix is never left sending a token we now reject.
     *
     * @return array{success: bool, message: string|null, data: array<string, mixed>|null}
     */
    public function provisionWebhookKey(CloudonixSettings $settings): array
    {
        $settings->domain_requests_api_key = Str::random(48);
        $settings->save();

        $result = $this->push($settings);

        if (! $result['success']) {
            $settings->domain_requests_api_key = null;
            $settings->save();
        }

        return $result;
    }
}
