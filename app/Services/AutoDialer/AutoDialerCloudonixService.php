<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\AmdMode;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\CloudonixSettings;
use App\Services\CloudonixClient\CloudonixClient;
use App\Services\PhoneNumberService;
use App\Services\VoiceRouting\OutboundRoutingService;
use Illuminate\Support\Facades\Log;

/**
 * Auto Dialer Cloudonix Integration Service
 *
 * Handles outbound call initiation and management via Cloudonix API.
 * Uses organization-specific Cloudonix credentials and outbound whitelist routing.
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

            // Create Cloudonix client with organization's credentials
            $client = $this->createClient($settings);

            // Determine outbound trunk based on whitelist rules
            $trunk = $this->determineOutboundTrunk($campaign, $destination);

            // Build routing configuration based on campaign settings
            $routingOptions = $this->buildRoutingOptions($campaign);

            // Build call options
            $options = [
                'timeout' => $campaign->dial_timeout,
                'timeLimit' => $campaign->time_limit,
                'recording' => $campaign->record_calls,
                'recordingStatusCallback' => $webhookUrl,
                'recordingStatusCallbackEvent' => 'completed',
            ];

            // Add AMD if enabled
            if ($campaign->amd_enabled) {
                $options['machineDetection'] = $this->mapAmdMode($campaign->amd_mode);
                $options['machineDetectionTimeout'] = $campaign->amd_timeout;
                $options['machineDetectionSpeechThreshold'] = $campaign->amd_speech_threshold;
                $options['machineDetectionSpeechEndThreshold'] = $campaign->amd_speech_end_threshold;
                $options['machineDetectionSilenceTimeout'] = $campaign->amd_silence_timeout;
            }

            // Merge routing options
            $options = array_merge($options, $routingOptions);

            Log::info('AutoDialer: Initiating call via Cloudonix', [
                'campaign_id' => $campaign->id,
                'destination_id' => $destination->id,
                'phone_number' => substr($destination->phone_number, 0, 8).'...',
                'routing_type' => $campaign->routing_destination_type,
                'amd_enabled' => $campaign->amd_enabled,
                'has_trunk' => $trunk !== null,
                'trunk' => $trunk,
            ]);

            // Initiate the call - only include trunk if determined by whitelist
            if ($trunk !== null) {
                $result = $client->initiateCall(
                    from: $campaign->caller_id,
                    to: $destination->phone_number,
                    trunk: $trunk,
                    options: $options
                );
            } else {
                // No whitelist rule matched - let Cloudonix determine trunk
                // by not providing the trunk parameter
                $result = $this->initiateCallWithoutTrunk($client, $campaign, $destination, $options);
            }

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
            ]);

            return [
                'success' => true,
                'call_id' => $result['callId'] ?? null,
                'session_token' => $result['sessionToken'] ?? null,
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
     * Initiate call without specifying trunk (let Cloudonix determine).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    private function initiateCallWithoutTrunk(
        CloudonixClient $client,
        AutoDialerCampaign $campaign,
        AutoDialerDestination $destination,
        array $options
    ): ?array {
        // Use reflection to call the protected client method with custom payload
        // that doesn't include the trunk parameter
        $payload = [
            'from' => $campaign->caller_id,
            'to' => $destination->phone_number,
        ];

        // Add optional parameters
        if (isset($options['timeout'])) {
            $payload['timeout'] = $options['timeout'];
        }

        if (isset($options['timeLimit'])) {
            $payload['timeLimit'] = $options['timeLimit'];
        }

        if (isset($options['recording']) && $options['recording']) {
            $payload['recording'] = true;

            if (isset($options['recordingStatusCallback'])) {
                $payload['recordingStatusCallback'] = $options['recordingStatusCallback'];
            }

            if (isset($options['recordingStatusCallbackEvent'])) {
                $payload['recordingStatusCallbackEvent'] = $options['recordingStatusCallbackEvent'];
            }
        }

        // Add AMD if enabled
        if (isset($options['machineDetection'])) {
            $payload['machineDetection'] = $options['machineDetection'];

            if (isset($options['machineDetectionTimeout'])) {
                $payload['machineDetectionTimeout'] = $options['machineDetectionTimeout'];
            }

            if (isset($options['machineDetectionSpeechThreshold'])) {
                $payload['machineDetectionSpeechThreshold'] = $options['machineDetectionSpeechThreshold'];
            }

            if (isset($options['machineDetectionSpeechEndThreshold'])) {
                $payload['machineDetectionSpeechEndThreshold'] = $options['machineDetectionSpeechEndThreshold'];
            }

            if (isset($options['machineDetectionSilenceTimeout'])) {
                $payload['machineDetectionSilenceTimeout'] = $options['machineDetectionSilenceTimeout'];
            }
        }

        // Add routing
        $payload = array_merge($payload, $this->buildRoutingOptions($campaign));

        Log::info('AutoDialer: Initiating call without trunk parameter', [
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'payload_keys' => array_keys($payload),
        ]);

        // Make direct API call without trunk
        return $this->makeDirectApiCall($client, $payload);
    }

    /**
     * Make direct API call to Cloudonix.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function makeDirectApiCall(CloudonixClient $client, array $payload): ?array
    {
        // Access the client's HTTP client using reflection
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('withCircuitBreaker');
        $method->setAccessible(true);

        return $method->invoke($client, function () use ($client, $payload) {
            // Get the HTTP client from CloudonixClient
            $clientReflection = new \ReflectionClass($client);
            $clientMethod = $clientReflection->getMethod('client');
            $clientMethod->setAccessible(true);

            $httpClient = $clientMethod->invoke($client);

            $response = $httpClient->post('/calls', $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('AutoDialer: Failed to initiate call without trunk', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }, null, null);
    }

    /**
     * Create a Cloudonix client with organization's credentials.
     */
    private function createClient(CloudonixSettings $settings): CloudonixClient
    {
        return new CloudonixClient($settings, true);
    }

    /**
     * Build routing options based on campaign destination type.
     *
     * @return array<string, mixed>
     */
    private function buildRoutingOptions(AutoDialerCampaign $campaign): array
    {
        $options = [];

        // Handle both enum and string values
        $routingType = $campaign->routing_destination_type;
        if ($routingType instanceof \App\Enums\RoutingDestinationType) {
            $routingType = $routingType->value;
        }

        switch ($routingType) {
            case 'ai_assistant':
                $options['application'] = "ai:{$campaign->routing_destination_id}";
                break;

            case 'ai_load_balancer':
                $options['application'] = "ai_lb:{$campaign->routing_destination_id}";
                break;

            case 'extension':
                $options['extension'] = $campaign->routing_destination_id;
                break;

            case 'ring_group':
                $options['ring_group'] = $campaign->routing_destination_id;
                break;

            case 'conference_room':
                $options['conference'] = $campaign->routing_destination_id;
                break;

            case 'ivr_menu':
                $options['ivr'] = $campaign->routing_destination_id;
                break;

            case 'hangup':
                $options['hangup'] = true;
                break;

            default:
                Log::warning('AutoDialer: Unknown routing destination type', [
                    'campaign_id' => $campaign->id,
                    'routing_type' => $routingType,
                ]);
                $options['hangup'] = true;
                break;
        }

        return $options;
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
            'detect_beep', 'DetectMessageEnd' => 'DetectMessageEnd',
            'detect_wait', 'Enabled' => 'Enabled',
            default => 'Enabled',
        };
    }

    /**
     * Get call status from Cloudonix.
     *
     * @return array<string, mixed>|null
     */
    public function getCallStatus(CloudonixSettings $settings, string $callId): ?array
    {
        $client = $this->createClient($settings);

        return $client->getCallStatus($callId);
    }

    /**
     * Hangup an active call.
     */
    public function hangupCall(CloudonixSettings $settings, string $callId): bool
    {
        $client = $this->createClient($settings);

        return $client->hangupCall($callId);
    }

    /**
     * Get CDR (Call Detail Record) for a call.
     *
     * @return array<string, mixed>|null
     */
    public function getCallCdr(CloudonixSettings $settings, string $callId): ?array
    {
        $client = $this->createClient($settings);

        return $client->getCallCdr($callId);
    }

    /**
     * Validate Cloudonix credentials.
     *
     * @return array{valid: bool, profile: array<string, mixed>|null}
     */
    public static function validateCredentials(string $domainUuid, string $apiKey): array
    {
        return CloudonixClient::validateDomainCredentials($domainUuid, $apiKey);
    }
}
