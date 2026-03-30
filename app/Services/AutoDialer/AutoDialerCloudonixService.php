<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Services\CloudonixClient\CloudonixClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Auto Dialer Cloudonix Integration Service
 *
 * Handles outbound call initiation and management via Cloudonix API.
 * This service integrates with the existing CloudonixClient and provides
 * dialer-specific functionality like campaign-based routing and AMD.
 */
class AutoDialerCloudonixService
{
    private CloudonixClient $client;

    public function __construct(CloudonixClient $client)
    {
        $this->client = $client;
    }

    /**
     * Initiate an outbound call for a campaign destination.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign initiating the call
     * @param  AutoDialerDestination  $destination  The destination to call
     * @param  string  $webhookUrl  The webhook URL for call status updates
     * @return array{success: bool, call_id: string|null, session_token: string|null, error: string|null}
     */
    public function initiateCall(
        AutoDialerCampaign $campaign,
        AutoDialerDestination $destination,
        string $webhookUrl
    ): array {
        try {
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
            ]);

            // Get the outbound trunk from config or use 'default'
            $trunk = Config::get('cloudonix.outbound_trunk', 'default');

            // Initiate the call
            $result = $this->client->initiateCall(
                from: $campaign->caller_id,
                to: $destination->phone_number,
                trunk: $trunk,
                options: $options
            );

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
     * Build routing options based on campaign destination type.
     *
     * @return array<string, mixed>
     */
    private function buildRoutingOptions(AutoDialerCampaign $campaign): array
    {
        $options = [];

        // Map routing destination type to Cloudonix routing
        // Handle both enum and string values
        $routingType = $campaign->routing_destination_type;
        if ($routingType instanceof \App\Enums\RoutingDestinationType) {
            $routingType = $routingType->value;
        }

        switch ($routingType) {
            case 'ai_assistant':
                // Route to AI assistant application
                $options['application'] = "ai:{$campaign->routing_destination_id}";
                break;

            case 'ai_load_balancer':
                // Route to AI load balancer
                $options['application'] = "ai_lb:{$campaign->routing_destination_id}";
                break;

            case 'extension':
                // Route to specific extension
                $options['extension'] = $campaign->routing_destination_id;
                break;

            case 'ring_group':
                // Route to ring group
                $options['ring_group'] = $campaign->routing_destination_id;
                break;

            case 'conference_room':
                // Route to conference room
                $options['conference'] = $campaign->routing_destination_id;
                break;

            case 'ivr_menu':
                // Route to IVR menu
                $options['ivr'] = $campaign->routing_destination_id;
                break;

            case 'hangup':
                // Just hangup (for testing/invalid scenarios)
                $options['hangup'] = true;
                break;

            default:
                Log::warning('AutoDialer: Unknown routing destination type', [
                    'campaign_id' => $campaign->id,
                    'routing_type' => $campaign->routing_destination_type,
                ]);
                // Default to hangup if no valid routing
                $options['hangup'] = true;
                break;
        }

        return $options;
    }

    /**
     * Map AMD mode to Cloudonix format.
     */
    private function mapAmdMode($mode): string
    {
        // Handle AmdMode enum
        if ($mode instanceof \App\Enums\AmdMode) {
            return $mode->value;
        }

        // Handle string values (legacy)
        return match ($mode) {
            'detect_beep', 'DetectMessageEnd' => 'DetectMessageEnd',
            'detect_wait', 'Enabled' => 'Enabled',
            default => 'Enabled', // 'detect' or null
        };
    }

    /**
     * Get call status from Cloudonix.
     *
     * @return array<string, mixed>|null
     */
    public function getCallStatus(string $callId): ?array
    {
        return $this->client->getCallStatus($callId);
    }

    /**
     * Hangup an active call.
     */
    public function hangupCall(string $callId): bool
    {
        return $this->client->hangupCall($callId);
    }

    /**
     * Get CDR (Call Detail Record) for a call.
     *
     * @return array<string, mixed>|null
     */
    public function getCallCdr(string $callId): ?array
    {
        return $this->client->getCallCdr($callId);
    }

    /**
     * Validate Cloudonix credentials.
     *
     * This is a static method that can be called without instantiating the service
     * to validate credentials before saving them.
     *
     * @return array{valid: bool, profile: array<string, mixed>|null}
     */
    public static function validateCredentials(string $domainUuid, string $apiKey): array
    {
        return CloudonixClient::validateDomainCredentials($domainUuid, $apiKey);
    }
}
