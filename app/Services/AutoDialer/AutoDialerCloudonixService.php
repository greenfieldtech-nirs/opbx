<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\AmdMode;
use App\Enums\RoutingDestinationType;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\CloudonixSettings;
use App\Services\PhoneNumberService;
use App\Services\VoiceRouting\OutboundRoutingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Auto Dialer Cloudonix Integration Service
 *
 * Handles outbound call initiation and management via Cloudonix API.
 * Uses organization-specific Cloudonix credentials and outbound whitelist routing.
 *
 * @see https://developers.cloudonix.com/Documentation/apiWorkflow/callControlAndSessionManagement#outbound-call-from-application
 */
class AutoDialerCloudonixService
{
    private OutboundRoutingService $outboundRouting;

    private PhoneNumberService $phoneNumberService;

    public function __construct(
        OutboundRoutingService $outboundRouting,
        PhoneNumberService $phoneNumberService
    ) {
        $this->outboundRouting = $outboundRouting;
        $this->phoneNumberService = $phoneNumberService;
    }

    /**
     * Initiate an outbound call for a campaign destination.
     *
     * Uses organization's Cloudonix credentials and outbound whitelist rules
     * to determine the appropriate trunk.
     *
     * @see https://developers.cloudonix.com/Documentation/apiWorkflow/callControlAndSessionManagement#outbound-call-from-application
     *
     * @param  AutoDialerCampaign  $campaign  The campaign initiating the call
     * @param  AutoDialerDestination  $destination  The destination to call
     * @param  CloudonixSettings  $settings  Organization's Cloudonix settings
     * @param  string  $webhookUrl  The webhook URL for call status updates
     * @return array{success: bool, call_id: string|null, session_token: string|null, error: string|null}
     */
    public function initiateCall(
        AutoDialerCampaign $campaign,
        AutoDialerDestination $destination,
        CloudonixSettings $settings,
        string $webhookUrl
    ): array {
        try {
            // Validate that Cloudonix is configured for this organization
            if (! $settings->isConfigured()) {
                Log::error('AutoDialer: Cloudonix not configured for organization', [
                    'campaign_id' => $campaign->id,
                    'organization_id' => $campaign->organization_id,
                ]);

                return [
                    'success' => false,
                    'call_id' => null,
                    'session_token' => null,
                    'error' => 'Cloudonix not configured for this organization',
                ];
            }

            // Build the payload according to Cloudonix API specification
            $payload = $this->buildPayload($campaign, $destination, $webhookUrl);

            Log::info('AutoDialer: Initiating call via Cloudonix', [
                'campaign_id' => $campaign->id,
                'destination_id' => $destination->id,
                'phone_number' => substr($destination->phone_number, 0, 8).'...',
                'routing_type' => $campaign->routing_destination_type,
                'amd_enabled' => $campaign->amd_enabled,
            ]);

            // Make the API call
            $result = $this->makeApiCall($settings, $payload);

            if ($result === null) {
                Log::error('AutoDialer: Failed to initiate call', [
                    'campaign_id' => $campaign->id,
                    'destination_id' => $destination->id,
                ]);

                return [
                    'success' => false,
                    'call_id' => null,
                    'session_token' => null,
                    'error' => 'Failed to initiate call via Cloudonix API',
                ];
            }

            Log::info('AutoDialer: Call initiated successfully', [
                'campaign_id' => $campaign->id,
                'destination_id' => $destination->id,
                'call_id' => $result['callId'] ?? null,
                'session_token' => $result['token'] ?? null,
            ]);

            return [
                'success' => true,
                'call_id' => $result['id'] ?? null,
                'session_token' => $result['token'] ?? null,
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::error('AutoDialer: Exception while initiating call', [
                'campaign_id' => $campaign->id,
                'destination_id' => $destination->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'call_id' => null,
                'session_token' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build the API payload according to Cloudonix specification.
     *
     * @see https://developers.cloudonix.com/Documentation/apiWorkflow/callControlAndSessionManagement#outbound-call-from-application
     *
     * @return array<string, mixed>
     */
    private function buildPayload(
        AutoDialerCampaign $campaign,
        AutoDialerDestination $destination,
        string $webhookUrl
    ): array {
        // Mandatory fields
        $payload = [
            'destination' => $destination->phone_number,
            'caller-id' => $campaign->caller_id,
        ];

        // Add routing destination (application, url, or cxml)
        $routingPayload = $this->buildRoutingPayload($campaign);
        $payload = array_merge($payload, $routingPayload);

        // Optional: Trunk (from outbound whitelist rules)
        $trunk = $this->determineOutboundTrunk($campaign, $destination);
        if ($trunk !== null) {
            $payload['trunk'] = $trunk;
        }

        // Optional: Timeout
        if ($campaign->dial_timeout) {
            $payload['timeout'] = $campaign->dial_timeout;
        }

        // Optional: Time limit
        if ($campaign->time_limit) {
            $payload['timeLimit'] = $campaign->time_limit;
        }

        // Optional: Recording
        if ($campaign->record_calls) {
            $payload['record'] = true;
            $payload['recordingStatusCallback'] = $webhookUrl;
            $payload['recordingStatusCallbackEvent'] = 'completed';
        }

        // Optional: AMD (Answering Machine Detection)
        if ($campaign->amd_enabled) {
            $payload['machineDetection'] = $this->mapAmdMode($campaign->amd_mode);

            if ($campaign->amd_timeout) {
                $payload['machineDetectionTimeout'] = $campaign->amd_timeout;
            }

            if ($campaign->amd_speech_threshold) {
                $payload['machineDetectionSpeechThreshold'] = $campaign->amd_speech_threshold;
            }

            if ($campaign->amd_speech_end_threshold) {
                $payload['machineDetectionSpeechEndThreshold'] = $campaign->amd_speech_end_threshold;
            }

            if ($campaign->amd_silence_timeout) {
                $payload['machineDetectionSilenceTimeout'] = $campaign->amd_silence_timeout;
            }
        }

        // Optional: Callback URL for session status updates
        $payload['callback'] = $webhookUrl;

        return $payload;
    }

    /**
     * Build routing payload (application, url, or cxml).
     *
     * @return array<string, mixed>
     */
    private function buildRoutingPayload(AutoDialerCampaign $campaign): array
    {
        // Handle both enum and string values
        $routingType = $campaign->routing_destination_type;
        if ($routingType instanceof RoutingDestinationType) {
            $routingType = $routingType->value;
        } elseif (is_object($routingType)) {
            // Handle enum objects that may be passed from database
            $routingType = (string) $routingType;
        }

        switch ($routingType) {
            case 'ai_assistant':
                // Route to AI assistant using the voice application ID
                return ['application' => $campaign->routing_destination_id];

            case 'ai_load_balancer':
                // Route to AI load balancer application
                return ['application' => $campaign->routing_destination_id];

            case 'extension':
                // For extension, we need to route to a CXML that dials the extension
                // This would be handled by a CXML application
                return ['application' => $this->getDialerApplicationId($campaign)];

            case 'ring_group':
                return ['application' => $this->getDialerApplicationId($campaign)];

            case 'conference_room':
                return ['application' => $this->getDialerApplicationId($campaign)];

            case 'ivr_menu':
                return ['application' => $this->getDialerApplicationId($campaign)];

            case 'hangup':
                // Use inline CXML to hangup
                return ['cxml' => $this->buildHangupCxml()];

            default:
                Log::warning('AutoDialer: Unknown routing destination type', [
                    'campaign_id' => $campaign->id,
                    'routing_type' => $routingType,
                ]);

                return ['cxml' => $this->buildHangupCxml()];
        }
    }

    /**
     * Get the dialer application ID for the campaign's organization.
     */
    private function getDialerApplicationId(AutoDialerCampaign $campaign): int
    {
        // For now, return the default application from Cloudonix settings
        // This should be the application's voice application ID
        // In a real implementation, you might want to store this per-campaign
        // or use a specific dialer application

        // Get the organization's default voice application
        $settings = CloudonixSettings::where('organization_id', $campaign->organization_id)->first();

        if ($settings && $settings->voice_application_id) {
            return $settings->voice_application_id;
        }

        // Fallback - this should be configured
        Log::warning('AutoDialer: No voice application configured for organization', [
            'campaign_id' => $campaign->id,
            'organization_id' => $campaign->organization_id,
        ]);

        return 0;
    }

    /**
     * Build hangup CXML for invalid/unknown routing types.
     */
    private function buildHangupCxml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>';
    }

    /**
     * Determine outbound trunk based on whitelist rules.
     *
     * @return string|null Returns trunk name if whitelist rule found, null otherwise
     */
    private function determineOutboundTrunk(
        AutoDialerCampaign $campaign,
        AutoDialerDestination $destination
    ): ?string {
        $whitelistEntry = $this->outboundRouting->findOutboundWhitelistEntry(
            $campaign->organization_id,
            $destination->phone_number
        );

        if ($whitelistEntry === null) {
            Log::info('AutoDialer: No outbound whitelist rule matched, letting Cloudonix determine trunk', [
                'campaign_id' => $campaign->id,
                'destination' => $destination->phone_number,
                'organization_id' => $campaign->organization_id,
            ]);

            return null;
        }

        if (empty($whitelistEntry->outbound_trunk_name)) {
            Log::warning('AutoDialer: Whitelist entry has no trunk configured', [
                'campaign_id' => $campaign->id,
                'whitelist_entry_id' => $whitelistEntry->id,
            ]);

            return null;
        }

        Log::info('AutoDialer: Using outbound trunk from whitelist', [
            'campaign_id' => $campaign->id,
            'destination' => $destination->phone_number,
            'trunk' => $whitelistEntry->outbound_trunk_name,
            'whitelist_entry_id' => $whitelistEntry->id,
        ]);

        return $whitelistEntry->outbound_trunk_name;
    }

    /**
     * Make the API call to Cloudonix.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function makeApiCall(CloudonixSettings $settings, array $payload): ?array
    {
        $baseUrl = rtrim(config('cloudonix.api.base_url', 'https://api.cloudonix.io'), '/');
        $domain = $settings->domain_uuid;
        $endpoint = "{$baseUrl}/calls/{$domain}/application";

        Log::debug('AutoDialer: Making Cloudonix API call', [
            'endpoint' => $endpoint,
            'payload' => $payload,
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$settings->domain_api_key,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($endpoint, $payload);

        if ($response->successful()) {
            $data = $response->json();

            Log::info('AutoDialer: Cloudonix API call successful', [
                'response' => $data,
            ]);

            return $data;
        }

        Log::error('AutoDialer: Cloudonix API call failed', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return null;
    }

    /**
     * Map AMD mode to Cloudonix format.
     *
     * @param  AmdMode|string|null  $mode
     */
    private function mapAmdMode($mode): string
    {
        if ($mode instanceof AmdMode) {
            return $mode->value;
        }

        return match ($mode) {
            'detect_beep' => 'DetectMessageEnd',
            'detect' => 'Enable',
            'detect_wait' => 'Enable',
            default => 'Enable',
        };
    }

    /**
     * Get call status from Cloudonix.
     *
     * @return array<string, mixed>|null
     */
    public function getCallStatus(CloudonixSettings $settings, string $callId): ?array
    {
        $baseUrl = rtrim(config('cloudonix.api.base_url', 'https://api.cloudonix.io'), '/');
        $domain = $settings->domain_uuid;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$settings->domain_api_key,
            'Accept' => 'application/json',
        ])->timeout(30)->get("{$baseUrl}/calls/{$domain}/sessions/{$callId}");

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * Hangup an active call.
     */
    public function hangupCall(CloudonixSettings $settings, string $callId): bool
    {
        $baseUrl = rtrim(config('cloudonix.api.base_url', 'https://api.cloudonix.io'), '/');
        $domain = $settings->domain_uuid;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$settings->domain_api_key,
            'Accept' => 'application/json',
        ])->timeout(30)->delete("{$baseUrl}/calls/{$domain}/sessions/{$callId}");

        return $response->successful();
    }

    /**
     * Get CDR (Call Detail Record) for a call.
     *
     * @return array<string, mixed>|null
     */
    public function getCallCdr(CloudonixSettings $settings, string $callId): ?array
    {
        // CDR is typically retrieved via webhook or a separate endpoint
        // For now, return session details which include call information
        return $this->getCallStatus($settings, $callId);
    }

    /**
     * Validate Cloudonix credentials.
     *
     * @return array{valid: bool, profile: array<string, mixed>|null}
     */
    public static function validateCredentials(string $domainUuid, string $apiKey): array
    {
        $baseUrl = rtrim(config('cloudonix.api.base_url', 'https://api.cloudonix.io'), '/');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ])->timeout(30)->get("{$baseUrl}/customers/self/domains/{$domainUuid}");

            $success = $response->successful();

            return [
                'valid' => $success,
                'profile' => $success ? $response->json() : null,
            ];
        } catch (\Exception $e) {
            Log::error('AutoDialer: Credential validation failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'valid' => false,
                'profile' => null,
            ];
        }
    }
}
