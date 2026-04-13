<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\RoutingDestinationType;
use App\Models\AiAssistant;
use App\Models\AutoDialerCampaign;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\AiAssistant\ProviderRegistry;
use App\Services\AiAssistant\WebSocketUrlBuilder;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Support\Facades\Log;

/**
 * CXML Generation Service
 *
 * Generates CXML for dialer outbound call routing.
 * Supports AI Assistant, Ring Group, IVR Menu, and Extension routing.
 */
class CxmlGenerationService
{
    /**
     * Generate CXML for outbound call routing based on campaign configuration.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign configuration
     * @param  array<string, mixed>  $params  Call parameters (session_id, phone_number, call_sid)
     * @return string The generated CXML
     *
     * @throws \InvalidArgumentException If routing configuration is invalid
     */
    public function generateRoutingCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $routingType = $campaign->routing_destination_type;

        // Handle based on enum value - note: RING_GROUP, IVR_MENU, EXTENSION
        // are legacy routing types that may exist in data but not in current enum
        return match ($routingType) {
            RoutingDestinationType::AI_ASSISTANT => $this->generateAiAssistantCxml($campaign, $params),
            RoutingDestinationType::AI_LOAD_BALANCER => $this->generateAiLoadBalancerCxml($campaign, $params),
            default => $this->handleLegacyRoutingType($campaign, $params, $routingType),
        };
    }

    /**
     * Handle legacy routing types that may exist in data but not in current enum.
     *
     * @param  array<string, mixed>  $params  Call parameters
     *
     * @throws \InvalidArgumentException If routing type is not supported
     */
    private function handleLegacyRoutingType(AutoDialerCampaign $campaign, array $params, ?RoutingDestinationType $routingType): string
    {
        // Handle legacy string-based routing types
        $typeValue = $routingType?->value ?? '';

        return match ($typeValue) {
            'ring_group' => $this->generateRingGroupCxml($campaign, $params),
            'ivr_menu' => $this->generateIvrMenuCxml($campaign, $params),
            'extension' => $this->generateExtensionCxml($campaign, $params),
            'hangup' => $this->generateHangupCxml(),
            default => throw new \InvalidArgumentException("Unsupported routing type: {$typeValue}"),
        };
    }

    /**
     * Generate CXML for AI Assistant routing.
     *
     * Supports both WebSocket and SIP protocols.
     *
     * @param  array<string, mixed>  $params  Call parameters
     */
    public function generateAiAssistantCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $aiAssistant = $campaign->aiAssistant;

        if (! $aiAssistant) {
            throw new \InvalidArgumentException('AI Assistant not found for campaign');
        }

        $config = $aiAssistant->configuration ?? [];
        $protocol = $aiAssistant->protocol;
        $provider = $aiAssistant->provider;

        // Route based on protocol
        if ($protocol === 'websocket') {
            return $this->generateWebSocketCxml($aiAssistant, $config, $provider, $params);
        }

        return $this->generateSipCxml($aiAssistant, $config, $provider, $params);
    }

    /**
     * Generate CXML for WebSocket-based AI Assistant.
     *
     * @param  array<string, mixed>  $config  AI assistant configuration
     * @param  array<string, mixed>  $params  Call parameters
     */
    public function generateWebSocketCxml(AiAssistant $aiAssistant, array $config, ?string $provider, array $params): string
    {
        if (! $provider) {
            throw new \InvalidArgumentException('AI Assistant provider not configured');
        }

        // Get provider registry to resolve URL template
        $providerRegistry = app(ProviderRegistry::class);
        $providerDef = $providerRegistry->getProvider($provider);

        if (! $providerDef || ! $providerDef->isWebSocketProvider()) {
            throw new \InvalidArgumentException('Invalid WebSocket provider configuration');
        }

        // Build WebSocket URL using the URL builder
        $urlBuilder = app(WebSocketUrlBuilder::class);
        $cloudonixParams = [
            'session' => $params['call_sid'],
            'from' => $params['phone_number'], // Outbound: destination is "from" in CXML context
            'to' => $campaign->caller_id ?? '', // Outbound: caller ID is "to" in CXML context
        ];

        $websocketUrl = $urlBuilder->buildUrl(
            $providerDef->urlTemplate,
            $config,
            $cloudonixParams
        );

        Log::debug('DialerWorker: Generated WebSocket URL for AI Assistant', [
            'ai_assistant_id' => $aiAssistant->id,
            'provider' => $provider,
            'websocket_url' => $websocketUrl,
        ]);

        // Generate CXML with Connect>Stream verb
        return CxmlBuilder::streamToWebSocket($websocketUrl);
    }

    /**
     * Generate CXML for SIP-based AI Assistant.
     *
     * @param  array<string, mixed>  $config  AI assistant configuration
     * @param  array<string, mixed>  $params  Call parameters
     */
    public function generateSipCxml(AiAssistant $aiAssistant, array $config, ?string $provider, array $params): string
    {
        // Check if AI Assistant has service URL (preferred for generic service URLs)
        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('ai_assistant_id', $aiAssistant->id)
            ->where('organization_id', $aiAssistant->organization_id)
            ->first();

        if ($extension && $extension->service_url) {
            Log::debug('DialerWorker: Using extension service URL for SIP routing', [
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
            throw new \InvalidArgumentException('SIP AI Assistant missing provider or phone number');
        }

        Log::debug('DialerWorker: Using provider format for SIP routing', [
            'ai_assistant_id' => $aiAssistant->id,
            'provider' => $provider,
            'phone_number' => $phoneNumber,
        ]);

        return CxmlBuilder::dialServiceProvider($provider, $phoneNumber);
    }

    /**
     * Generate CXML for AI Load Balancer routing.
     *
     * @param  array<string, mixed>  $params  Call parameters
     */
    public function generateAiLoadBalancerCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $loadBalancer = $campaign->aiLoadBalancer;

        if (! $loadBalancer) {
            throw new \InvalidArgumentException('AI Load Balancer not found for campaign');
        }

        // Get the active AI assistant from the load balancer
        $aiAssistant = $loadBalancer->getActiveAssistant();

        if (! $aiAssistant) {
            throw new \InvalidArgumentException('No active AI Assistant found in load balancer');
        }

        $config = $aiAssistant->configuration ?? [];
        $protocol = $aiAssistant->protocol;
        $provider = $aiAssistant->provider;

        if ($protocol === 'websocket') {
            return $this->generateWebSocketCxml($aiAssistant, $config, $provider, $params);
        }

        return $this->generateSipCxml($aiAssistant, $config, $provider, $params);
    }

    /**
     * Generate CXML for Ring Group routing.
     *
     * @param  array<string, mixed>  $params  Call parameters
     */
    public function generateRingGroupCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $ringGroup) {
            throw new \InvalidArgumentException('Ring group not found');
        }

        // Get members' SIP URIs
        $sipUris = [];
        foreach ($ringGroup->members as $member) {
            if ($member->extension && $member->extension->isActive()) {
                $sipUris[] = $member->extension->getSipUri();
            }
        }

        if (empty($sipUris)) {
            throw new \InvalidArgumentException('Ring group has no active members');
        }

        Log::debug('DialerWorker: Generated ring group CXML', [
            'ring_group_id' => $ringGroup->id,
            'member_count' => count($sipUris),
        ]);

        // Generate webhook callback URL for ring group fallback
        $webhookBaseUrl = config('app.webhook_base_url') ?? config('app.url');
        $actionUrl = rtrim($webhookBaseUrl, '/').'/api/voice/ring-group-callback?ring_group_id='.$ringGroup->id;

        return CxmlBuilder::dialRingGroup($sipUris, $campaign->dial_timeout ?? 30, $actionUrl);
    }

    /**
     * Generate CXML for IVR Menu routing.
     *
     * @param  array<string, mixed>  $params  Call parameters
     */
    public function generateIvrMenuCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $ivrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $ivrMenu) {
            throw new \InvalidArgumentException('IVR menu not found');
        }

        // Generate webhook URL for IVR input handling
        $webhookBaseUrl = config('app.webhook_base_url') ?? config('app.url');
        $actionUrl = rtrim($webhookBaseUrl, '/').'/api/voice/ivr-input?menu_id='.$ivrMenu->id;

        // Build the IVR prompt
        $nestedVerbs = '';
        if ($ivrMenu->greeting_audio_url) {
            $nestedVerbs .= CxmlBuilder::playXml($ivrMenu->greeting_audio_url);
        } elseif ($ivrMenu->greeting_text) {
            $nestedVerbs .= CxmlBuilder::sayXml($ivrMenu->greeting_text);
        }

        Log::debug('DialerWorker: Generated IVR menu CXML', [
            'ivr_menu_id' => $ivrMenu->id,
            'has_audio' => (bool) $ivrMenu->greeting_audio_url,
        ]);

        return CxmlBuilder::gather(
            $nestedVerbs,
            $actionUrl,
            $ivrMenu->timeout ?? 5,
            $ivrMenu->finish_on_key ?? '#',
            $ivrMenu->min_digits ?? 1,
            $ivrMenu->max_digits ?? 1
        );
    }

    /**
     * Generate CXML for Extension routing.
     *
     * @param  array<string, mixed>  $params  Call parameters
     */
    public function generateExtensionCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $extension) {
            throw new \InvalidArgumentException('Extension not found');
        }

        Log::debug('DialerWorker: Generated extension CXML', [
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
        ]);

        return CxmlBuilder::dialExtension($extension->getSipUri(), $campaign->dial_timeout ?? 30);
    }

    /**
     * Generate a simple hangup CXML.
     */
    public function generateHangupCxml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>';
    }
}
