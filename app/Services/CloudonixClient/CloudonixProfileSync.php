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
     * Webhook endpoints on the domain profile that point somewhere other than this installation.
     *
     * Two OPBX installations sharing one Cloudonix domain (e.g. production and a dev machine
     * behind ngrok) overwrite each other's endpoints and webhook key on every save: the last
     * save wins and the other installation stops receiving session updates and CDRs.
     *
     * @param  array<string, mixed>  $domainProfile  The domain's `profile` object as read from Cloudonix
     * @return array<string, string> Profile key => URL currently set there, for each mismatch
     */
    public function foreignEndpoints(CloudonixSettings $settings, array $domainProfile): array
    {
        $ours = [
            'session-update-endpoint' => $settings->getCallbackUrl(),
            'cdr-endpoint' => $settings->getCdrUrl(),
        ];

        $foreign = [];
        foreach ($ours as $key => $url) {
            $current = $domainProfile[$key] ?? null;
            if (is_string($current) && $current !== '' && rtrim($current, '/') !== rtrim($url, '/')) {
                $foreign[$key] = $current;
            }
        }

        return $foreign;
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
