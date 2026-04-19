<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\RoutingDestinationType;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\CloudonixSettings;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\AiAssistant\ProviderRegistry;
use App\Services\AiAssistant\WebSocketUrlBuilder;
use App\Services\CxmlBuilder\CxmlBuilder;
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
        string $webhookUrl,
        ?string $selectedCallerId = null,
        ?int $selectedCallerDidId = null
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
            $payload = $this->buildPayload($campaign, $destination, $webhookUrl, $selectedCallerId);

            Log::info('AutoDialer: Initiating call via Cloudonix', [
                'campaign_id' => $campaign->id,
                'destination_id' => $destination->id,
                'phone_number' => substr($destination->phone_number, 0, 8).'...',
                'routing_type' => $campaign->routing_destination_type,
                'amd_actions' => [
                    'voicemail' => $campaign->action_voicemail,
                    'human' => $campaign->action_human,
                    'unknown' => $campaign->action_unknown,
                ],
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
        string $webhookUrl,
        ?string $selectedCallerId = null
    ): array {
        // Use selected Caller ID from pool if provided, otherwise fall back to campaign default
        $callerId = $selectedCallerId ?? $campaign->caller_id;

        // Mandatory fields
        $payload = [
            'destination' => $destination->phone_number,
            'caller-id' => $callerId,
        ];

        // Add routing destination (application, url, or cxml)
        $routingPayload = $this->buildRoutingPayload($campaign, $destination);
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

        // Optional: Callback URL for session status updates
        $payload['callback'] = $webhookUrl;

        return $payload;
    }

    /**
     * Build routing payload (application, url, or cxml).
     *
     * @return array<string, mixed>
     */
    private function buildRoutingPayload(AutoDialerCampaign $campaign, AutoDialerDestination $destination): array
    {
        // Generate CXML based on campaign routing type
        $cxml = $this->generateCxmlForCampaign($campaign, $destination);

        return ['cxml' => $cxml];
    }

    /**
     * Generate CXML for a campaign based on its routing destination type.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to generate CXML for
     * @return string The generated CXML
     */
    private function generateCxmlForCampaign(AutoDialerCampaign $campaign, AutoDialerDestination $destination): string
    {
        try {
            // Handle both enum and string values
            $routingType = $campaign->routing_destination_type;
            if ($routingType instanceof RoutingDestinationType) {
                $routingType = $routingType->value;
            } elseif (is_object($routingType)) {
                // Handle enum objects that may be passed from database
                $routingType = (string) $routingType;
            }

            // Build Cloudonix-style parameters for WebSocket URL substitution
            $cloudonixParams = [
                'session' => uniqid('ad-', true),
                'from' => $campaign->caller_id ?? '',
                'to' => $destination->phone_number ?? '',
            ];

            switch ($routingType) {
                case 'ai_assistant':
                    $innerCxml = $this->generateAiAssistantCxml($campaign, $cloudonixParams);
                    break;

                case 'ai_load_balancer':
                    $innerCxml = $this->generateAiLoadBalancerCxml($campaign, $cloudonixParams);
                    break;

                case 'extension':
                    $innerCxml = $this->generateExtensionCxml($campaign);
                    break;

                case 'ring_group':
                    $innerCxml = $this->generateRingGroupCxml($campaign);
                    break;

                case 'conference_room':
                    $innerCxml = $this->generateConferenceRoomCxml($campaign);
                    break;

                case 'ivr_menu':
                    $innerCxml = $this->generateIvrMenuCxml($campaign);
                    break;

                case 'hangup':
                    $innerCxml = $this->buildHangupCxml();
                    break;

                default:
                    Log::warning('AutoDialer: Unknown routing destination type', [
                        'campaign_id' => $campaign->id,
                        'routing_type' => $routingType,
                    ]);

                    $innerCxml = $this->buildHangupCxml();
            }

            // Wrap with WebSocket-based AMD stream if actions are configured
            return $this->wrapCxmlWithAmdStream($campaign, $innerCxml);
        } catch (\Exception $e) {
            Log::error('AutoDialer: Failed to generate CXML for campaign', [
                'campaign_id' => $campaign->id,
                'routing_type' => $campaign->routing_destination_type?->value ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return $this->buildHangupCxml();
        }
    }

    /**
     * Generate CXML for AI Assistant routing.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign
     * @return string The generated CXML
     */
    /**
     * @param  array<string, string>  $cloudonixParams  Runtime params (session, from, to)
     */
    private function generateAiAssistantCxml(AutoDialerCampaign $campaign, array $cloudonixParams): string
    {
        $aiAssistant = AiAssistant::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $aiAssistant) {
            Log::error('AutoDialer: AI Assistant not found for campaign', [
                'campaign_id' => $campaign->id,
                'ai_assistant_id' => $campaign->routing_destination_id,
            ]);

            return $this->buildHangupCxml();
        }

        $config = $aiAssistant->configuration ?? [];
        $protocol = $aiAssistant->protocol;
        $provider = $aiAssistant->provider;

        if ($protocol === 'websocket') {
            return $this->generateWebSocketCxml($aiAssistant, $config, $provider, $cloudonixParams);
        }

        if ($protocol === 'dummy') {
            return $this->generateDummyCxml($aiAssistant);
        }

        return $this->generateSipCxml($aiAssistant, $config, $provider);
    }

    /**
     * Generate CXML for WebSocket-based AI Assistant.
     *
     * @param  AiAssistant  $aiAssistant  The AI assistant
     * @param  array<string, mixed>  $config  The assistant configuration
     * @param  string|null  $provider  The provider name
     * @return string The generated CXML
     */
    /**
     * @param  array<string, mixed>  $config  AI assistant configuration
     * @param  array<string, string>  $cloudonixParams  Runtime params (session, from, to)
     */
    private function generateWebSocketCxml(AiAssistant $aiAssistant, array $config, ?string $provider, array $cloudonixParams): string
    {
        if (! $provider) {
            Log::error('AutoDialer: AI Assistant provider not configured', [
                'ai_assistant_id' => $aiAssistant->id,
            ]);

            return $this->buildHangupCxml();
        }

        // Get provider registry to resolve URL template
        $providerRegistry = app(ProviderRegistry::class);
        $providerDef = $providerRegistry->getProvider($provider);

        if (! $providerDef || ! $providerDef->isWebSocketProvider()) {
            Log::error('AutoDialer: Invalid WebSocket provider configuration', [
                'ai_assistant_id' => $aiAssistant->id,
                'provider' => $provider,
            ]);

            return $this->buildHangupCxml();
        }

        // Build WebSocket URL — substitute both config values and Cloudonix params
        $urlBuilder = app(WebSocketUrlBuilder::class);
        $websocketUrl = $urlBuilder->buildUrl(
            $providerDef->urlTemplate,
            $config,
            $cloudonixParams
        );

        Log::debug('AutoDialer: Generated WebSocket URL for AI Assistant', [
            'ai_assistant_id' => $aiAssistant->id,
            'provider' => $provider,
            'websocket_url' => $websocketUrl,
        ]);

        // Generate CXML with Connect>Stream verb
        return CxmlBuilder::streamToWebSocket($websocketUrl);
    }

    /**
     * Generate CXML for dummy AI Assistant.
     *
     * @param  AiAssistant  $aiAssistant  The AI assistant
     * @return string The generated CXML
     */
    private function generateDummyCxml(AiAssistant $aiAssistant): string
    {
        Log::debug('AutoDialer: Using dummy AI provider', [
            'ai_assistant_id' => $aiAssistant->id,
        ]);

        return CxmlBuilder::dummyAiMessage();
    }

    /**
     * Generate CXML for SIP-based AI Assistant.
     *
     * @param  AiAssistant  $aiAssistant  The AI assistant
     * @param  array<string, mixed>  $config  The assistant configuration
     * @param  string|null  $provider  The provider name
     * @return string The generated CXML
     */
    private function generateSipCxml(AiAssistant $aiAssistant, array $config, ?string $provider): string
    {
        // Check if AI Assistant has service URL (preferred for generic service URLs)
        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('ai_assistant_id', $aiAssistant->id)
            ->where('organization_id', $aiAssistant->organization_id)
            ->first();

        if ($extension && $extension->service_url) {
            Log::debug('AutoDialer: Using extension service URL for SIP routing', [
                'ai_assistant_id' => $aiAssistant->id,
                'extension_id' => $extension->id,
                'service_url' => $extension->service_url,
            ]);

            return CxmlBuilder::dialService(
                $extension->service_url,
                $extension->service_token,
                $extension->service_params ?? []
            );
        }

        // Fall back to legacy provider + phone number format
        $phoneNumber = $config['phone_number'] ?? null;

        if (! $provider || ! $phoneNumber) {
            Log::error('AutoDialer: SIP AI Assistant missing provider or phone number', [
                'ai_assistant_id' => $aiAssistant->id,
                'provider' => $provider,
                'has_phone_number' => ! empty($phoneNumber),
            ]);

            return $this->buildHangupCxml();
        }

        Log::debug('AutoDialer: Using provider format for SIP routing', [
            'ai_assistant_id' => $aiAssistant->id,
            'provider' => $provider,
            'phone_number' => $phoneNumber,
        ]);

        return CxmlBuilder::dialServiceProvider($provider, $phoneNumber);
    }

    /**
     * Generate CXML for AI Load Balancer routing.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign
     * @return string The generated CXML
     */
    /**
     * @param  array<string, string>  $cloudonixParams  Runtime params (session, from, to)
     */
    private function generateAiLoadBalancerCxml(AutoDialerCampaign $campaign, array $cloudonixParams): string
    {
        $loadBalancer = AiAssistantLoadBalancer::withoutGlobalScope(OrganizationScope::class)
            ->with(['members' => function ($query) {
                $query->where('status', 'active')
                    ->whereHas('aiAssistant', function ($q) {
                        $q->withoutGlobalScope(OrganizationScope::class)
                            ->where('status', 'active');
                    })
                    ->with(['aiAssistant' => function ($q) {
                        $q->withoutGlobalScope(OrganizationScope::class);
                    }]);
            }])
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $loadBalancer) {
            Log::error('AutoDialer: AI Load Balancer not found for campaign', [
                'campaign_id' => $campaign->id,
                'load_balancer_id' => $campaign->routing_destination_id,
            ]);

            return $this->buildHangupCxml();
        }

        // Select AI assistant using the distribution service (respects strategy)
        $distributionService = app(\App\Services\VoiceRouting\AlbsDistributionService::class);
        $aiAssistant = $distributionService->selectAssistant($loadBalancer);

        if (! $aiAssistant) {
            Log::error('AutoDialer: No active AI Assistant found in load balancer', [
                'campaign_id' => $campaign->id,
                'load_balancer_id' => $loadBalancer->id,
            ]);

            return $this->buildHangupCxml();
        }

        // Build the follow-through callback URL so Cloudonix can try the next
        // assistant if this one fails (busy, no-answer, etc.)
        $callbackUrl = $this->buildAlbFollowThroughUrl($loadBalancer, $aiAssistant, $campaign);

        $config = $aiAssistant->configuration ?? [];
        $protocol = $aiAssistant->protocol;
        $provider = $aiAssistant->provider;

        Log::info('AutoDialer: Routing to AI Load Balancer assistant', [
            'campaign_id' => $campaign->id,
            'load_balancer_id' => $loadBalancer->id,
            'assistant_id' => $aiAssistant->id,
            'assistant_name' => $aiAssistant->name,
            'protocol' => $protocol,
            'callback_url' => $callbackUrl,
        ]);

        if ($protocol === 'websocket') {
            return $this->generateWebSocketCxmlWithAction($aiAssistant, $config, $provider, $cloudonixParams, $callbackUrl);
        }

        if ($protocol === 'dummy') {
            return $this->generateDummyCxml($aiAssistant);
        }

        return $this->generateSipCxmlWithAction($aiAssistant, $config, $provider, $callbackUrl);
    }

    /**
     * Build the ALB follow-through callback URL.
     */
    private function buildAlbFollowThroughUrl(
        AiAssistantLoadBalancer $loadBalancer,
        AiAssistant $currentAssistant,
        AutoDialerCampaign $campaign
    ): string {
        $cloudonixSettings = \App\Models\CloudonixSettings::where('organization_id', $campaign->organization_id)->first();

        $baseUrl = $cloudonixSettings
            ? rtrim($cloudonixSettings->effective_webhook_base_url ?? config('app.url'), '/')
            : rtrim(config('app.url'), '/');

        $relativeUrl = route('voice.albs-follow-through', [
            'albs_id' => $loadBalancer->id,
            'current_assistant_id' => $currentAssistant->id,
        ], false);

        return $baseUrl.$relativeUrl;
    }

    /**
     * Generate WebSocket CXML with follow-through action callback.
     */
    private function generateWebSocketCxmlWithAction(AiAssistant $aiAssistant, array $config, ?string $provider, array $cloudonixParams, string $callbackUrl): string
    {
        if (! $provider) {
            return $this->buildHangupCxml();
        }

        $providerRegistry = app(ProviderRegistry::class);
        $providerDef = $providerRegistry->getProvider($provider);

        if (! $providerDef || ! $providerDef->isWebSocketProvider()) {
            return $this->buildHangupCxml();
        }

        try {
            $urlBuilder = app(WebSocketUrlBuilder::class);
            $websocketUrl = $urlBuilder->buildUrl($providerDef->urlTemplate, $config, $cloudonixParams);

            return CxmlBuilder::streamToWebSocketWithAction($websocketUrl, $callbackUrl);
        } catch (\InvalidArgumentException $e) {
            Log::error('AutoDialer: Failed to build WebSocket URL for ALB', [
                'ai_assistant_id' => $aiAssistant->id,
                'error' => $e->getMessage(),
            ]);

            return $this->buildHangupCxml();
        }
    }

    /**
     * Generate SIP CXML with follow-through action callback.
     */
    private function generateSipCxmlWithAction(AiAssistant $aiAssistant, array $config, ?string $provider, string $callbackUrl): string
    {
        $phoneNumber = $config['phone_number'] ?? null;

        if (! $provider || ! $phoneNumber) {
            return $this->buildHangupCxml();
        }

        return CxmlBuilder::dialServiceProviderWithAction($provider, $phoneNumber, $callbackUrl);
    }

    /**
     * Get active AI assistant from load balancer.
     *
     * @param  AiAssistantLoadBalancer  $loadBalancer  The load balancer
     * @return AiAssistant|null The active AI assistant or null
     */
    private function getActiveAssistantFromLoadBalancer(AiAssistantLoadBalancer $loadBalancer): ?AiAssistant
    {
        // Get active members
        $activeMembers = $loadBalancer->members()
            ->where('status', 'active')
            ->whereHas('aiAssistant', fn ($q) => $q->where('status', 'active'))
            ->orderBy('position')
            ->get();

        if ($activeMembers->isEmpty()) {
            return null;
        }

        // Simple round-robin: get the first active member
        // In a production environment, you might want to track last used member
        $member = $activeMembers->first();

        return $member?->aiAssistant;
    }

    /**
     * Generate CXML for Extension routing.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign
     * @return string The generated CXML
     */
    private function generateExtensionCxml(AutoDialerCampaign $campaign): string
    {
        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $extension) {
            Log::error('AutoDialer: Extension not found for campaign', [
                'campaign_id' => $campaign->id,
                'extension_id' => $campaign->routing_destination_id,
            ]);

            return $this->buildHangupCxml();
        }

        if (! $extension->isActive()) {
            Log::error('AutoDialer: Extension is not active', [
                'campaign_id' => $campaign->id,
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return $this->buildHangupCxml();
        }

        $sipUri = $extension->getSipUri();

        if (! $sipUri) {
            Log::error('AutoDialer: Extension has no valid SIP URI', [
                'campaign_id' => $campaign->id,
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return $this->buildHangupCxml();
        }

        Log::debug('AutoDialer: Generated extension CXML', [
            'campaign_id' => $campaign->id,
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
        ]);

        return CxmlBuilder::dialExtension($sipUri, $campaign->dial_timeout ?? 30);
    }

    /**
     * Generate CXML for Ring Group routing.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign
     * @return string The generated CXML
     */
    private function generateRingGroupCxml(AutoDialerCampaign $campaign): string
    {
        $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $ringGroup) {
            Log::error('AutoDialer: Ring group not found for campaign', [
                'campaign_id' => $campaign->id,
                'ring_group_id' => $campaign->routing_destination_id,
            ]);

            return $this->buildHangupCxml();
        }

        if (! $ringGroup->isActive()) {
            Log::error('AutoDialer: Ring group is not active', [
                'campaign_id' => $campaign->id,
                'ring_group_id' => $ringGroup->id,
            ]);

            return $this->buildHangupCxml();
        }

        // Get members' SIP URIs
        $sipUris = [];
        foreach ($ringGroup->members as $member) {
            if ($member->extension && $member->extension->isActive()) {
                $sipUri = $member->extension->getSipUri();
                if ($sipUri) {
                    $sipUris[] = $sipUri;
                }
            }
        }

        if (empty($sipUris)) {
            Log::error('AutoDialer: Ring group has no active members', [
                'campaign_id' => $campaign->id,
                'ring_group_id' => $ringGroup->id,
            ]);

            return $this->buildHangupCxml();
        }

        Log::debug('AutoDialer: Generated ring group CXML', [
            'campaign_id' => $campaign->id,
            'ring_group_id' => $ringGroup->id,
            'member_count' => count($sipUris),
        ]);

        // Generate webhook callback URL for ring group fallback
        $webhookBaseUrl = config('app.webhook_base_url') ?? config('app.url');
        $actionUrl = rtrim($webhookBaseUrl, '/').'/api/voice/ring-group-callback?ring_group_id='.$ringGroup->id;

        return CxmlBuilder::dialRingGroup($sipUris, $campaign->dial_timeout ?? 30, $actionUrl);
    }

    /**
     * Generate CXML for Conference Room routing.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign
     * @return string The generated CXML
     */
    private function generateConferenceRoomCxml(AutoDialerCampaign $campaign): string
    {
        $conferenceRoom = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $conferenceRoom) {
            Log::error('AutoDialer: Conference room not found for campaign', [
                'campaign_id' => $campaign->id,
                'conference_room_id' => $campaign->routing_destination_id,
            ]);

            return $this->buildHangupCxml();
        }

        if (! $conferenceRoom->isActive()) {
            Log::error('AutoDialer: Conference room is not active', [
                'campaign_id' => $campaign->id,
                'conference_room_id' => $conferenceRoom->id,
            ]);

            return $this->buildHangupCxml();
        }

        Log::debug('AutoDialer: Generated conference room CXML', [
            'campaign_id' => $campaign->id,
            'conference_room_id' => $conferenceRoom->id,
            'conference_room_name' => $conferenceRoom->name,
        ]);

        return CxmlBuilder::joinConference(
            'room_'.$conferenceRoom->id,
            $conferenceRoom->max_participants,
            $conferenceRoom->mute_on_entry,
            $conferenceRoom->announce_join_leave
        );
    }

    /**
     * Generate CXML for IVR Menu routing.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign
     * @return string The generated CXML
     */
    private function generateIvrMenuCxml(AutoDialerCampaign $campaign): string
    {
        $ivrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $ivrMenu) {
            Log::error('AutoDialer: IVR menu not found for campaign', [
                'campaign_id' => $campaign->id,
                'ivr_menu_id' => $campaign->routing_destination_id,
            ]);

            return $this->buildHangupCxml();
        }

        if (! $ivrMenu->isActive()) {
            Log::error('AutoDialer: IVR menu is not active', [
                'campaign_id' => $campaign->id,
                'ivr_menu_id' => $ivrMenu->id,
            ]);

            return $this->buildHangupCxml();
        }

        // Generate webhook URL for IVR input handling
        $webhookBaseUrl = config('app.webhook_base_url') ?? config('app.url');
        $actionUrl = rtrim($webhookBaseUrl, '/').'/api/voice/ivr-input?menu_id='.$ivrMenu->id;

        // Build the IVR prompt
        $nestedVerbs = '';
        if ($ivrMenu->audio_file_path) {
            $nestedVerbs .= CxmlBuilder::playXml($ivrMenu->audio_file_path);
        } elseif ($ivrMenu->tts_text) {
            $nestedVerbs .= CxmlBuilder::sayXml($ivrMenu->tts_text, $ivrMenu->tts_voice ?? null);
        }

        Log::debug('AutoDialer: Generated IVR menu CXML', [
            'campaign_id' => $campaign->id,
            'ivr_menu_id' => $ivrMenu->id,
            'has_audio' => (bool) $ivrMenu->audio_file_path,
        ]);

        return CxmlBuilder::gather(
            $nestedVerbs,
            $actionUrl,
            $ivrMenu->inter_digit_timeout ?? 5,
            '#',
            1,
            1,
            $ivrMenu->max_timeout ?? null
        );
    }

    /**
     * Get the dialer application ID for the campaign's organization.
     *
     * @deprecated This method is no longer used. CXML is now generated inline.
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
     * Wrap CXML with WebSocket-based AMD stream if campaign has actions configured.
     *
     * Extracts inner content from the existing CXML and rebuilds with
     * <Start><Stream url="..."><Parameter .../></Stream></Start> prepended.
     */
    private function wrapCxmlWithAmdStream(AutoDialerCampaign $campaign, string $cxml): string
    {
        // If no AMD actions are configured, return CXML as-is
        if (empty($campaign->action_voicemail) && empty($campaign->action_human) && empty($campaign->action_unknown)) {
            return $cxml;
        }

        $streamUrl = $this->buildAmdStreamUrl($campaign);
        if (! $streamUrl) {
            Log::warning('AutoDialer: Cannot build AMD stream URL, returning CXML without AMD', [
                'campaign_id' => $campaign->id,
            ]);

            return $cxml;
        }

        // Extract inner content from existing <Response>...</Response>
        $innerContent = $this->extractResponseInnerContent($cxml);

        // Build AMD stream parameters
        $params = [];
        if ($campaign->action_voicemail) {
            $params[] = $this->buildAmdParameter('action_voicemail', $campaign->action_voicemail);
        }
        if ($campaign->action_human) {
            $params[] = $this->buildAmdParameter('action_human', $campaign->action_human);
        }
        if ($campaign->action_unknown) {
            $params[] = $this->buildAmdParameter('action_unknown', $campaign->action_unknown);
        }

        $parametersXml = implode("\n        ", $params);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Start>
    <Stream url="{$streamUrl}">
        {$parametersXml}
    </Stream>
  </Start>
  {$innerContent}
</Response>
XML;
    }

    /**
     * Build the AMD stream WebSocket URL from Cloudonix settings.
     */
    private function buildAmdStreamUrl(AutoDialerCampaign $campaign): ?string
    {
        $settings = CloudonixSettings::where('organization_id', $campaign->organization_id)->first();
        if (! $settings) {
            return null;
        }

        $baseUrl = rtrim($settings->webhook_base_url ?? config('app.url'), '/');
        // Convert https:// to wss:// and http:// to ws://
        $baseUrl = preg_replace('/^https:\/\//', 'wss://', $baseUrl);
        $baseUrl = preg_replace('/^http:\/\//', 'ws://', $baseUrl);

        return "{$baseUrl}/ws/amd/detect";
    }

    /**
     * Extract inner content from a <Response>...</Response> XML string.
     */
    private function extractResponseInnerContent(string $cxml): string
    {
        // Strip XML declaration
        $cxml = preg_replace('/<\?xml[^?]*\?>/', '', $cxml);
        // Strip <Response> and </Response> tags
        $cxml = preg_replace('/<Response>\s*/', '', $cxml);
        $cxml = preg_replace('/\s*<\/Response>/', '', $cxml);

        return trim($cxml);
    }

    /**
     * Build a single AMD action parameter XML element.
     */
    private function buildAmdParameter(string $name, string $value): string
    {
        $escapedValue = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return "<Parameter name=\"{$name}\" value=\"{$escapedValue}\" />";
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
