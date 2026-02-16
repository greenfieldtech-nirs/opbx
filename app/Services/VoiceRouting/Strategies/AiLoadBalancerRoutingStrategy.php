<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\AlbsStatus;
use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Enums\UserStatus;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use App\Services\AiAssistant\ProviderRegistry;
use App\Services\AiAssistant\WebSocketUrlBuilder;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\AlbsDistributionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class AiLoadBalancerRoutingStrategy implements RoutingStrategy
{
    public function __construct(
        private readonly AlbsDistributionService $distributionService,
        private readonly ProviderRegistry $providerRegistry,
        private readonly WebSocketUrlBuilder $urlBuilder
    ) {}

    /**
     * Generate follow-through callback URL for ALB failover.
     *
     * This URL is used when an AI Assistant call fails (busy, no-answer, failed, etc.)
     * and we need to try the next assistant in the load balancer.
     *
     * @param  AiAssistantLoadBalancer  $aiLoadBalancer  The load balancer
     * @param  AiAssistant  $currentAssistant  The assistant that just failed
     * @param  Request  $request  The incoming request
     * @return string The callback URL
     */
    private function getFollowThroughCallbackUrl(
        AiAssistantLoadBalancer $aiLoadBalancer,
        AiAssistant $currentAssistant,
        Request $request
    ): string {
        $organizationId = $request->input('_organization_id');
        $cloudonixSettings = \App\Models\CloudonixSettings::where('organization_id', $organizationId)->first();

        $baseUrl = $cloudonixSettings && $cloudonixSettings->webhook_base_url
            ? rtrim($cloudonixSettings->webhook_base_url, '/')
            : $request->getSchemeAndHttpHost();

        $relativeUrl = route('voice.albs-follow-through', [
            'albs_id' => $aiLoadBalancer->id,
            'current_assistant_id' => $currentAssistant->id,
        ], false);

        return $baseUrl.$relativeUrl;
    }

    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::AI_LOAD_BALANCER;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        // Check if we're receiving an Extension model (normal routing) or an ALB model directly (fallback routing)
        $extension = $destination['extension'] ?? null;
        $aiLoadBalancerModel = $destination['ai_load_balancer'] ?? null;

        // Case 1: Direct ALB model passed (from ring group fallback)
        if ($aiLoadBalancerModel instanceof AiAssistantLoadBalancer) {
            return $this->routeWithLoadBalancer($request, $aiLoadBalancerModel);
        }

        // Case 2: Extension model passed (normal routing from extension)
        if (! $extension instanceof Extension) {
            Log::error('AiLoadBalancerRoutingStrategy: Extension not found in destination', [
                'destination_keys' => array_keys($destination),
            ]);

            return response(
                CxmlBuilder::unavailable('Extension configuration error'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        $config = $extension->configuration ?? [];
        $aiLoadBalancerId = $config['ai_load_balancer_id'] ?? null;

        if (! $aiLoadBalancerId) {
            Log::error('AiLoadBalancerRoutingStrategy: No AI Load Balancer configured', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return response(
                CxmlBuilder::unavailable('AI Load Balancer not configured'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        $aiLoadBalancer = AiAssistantLoadBalancer::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->with(['members' => function ($query) {
                $query->where('status', 'active')
                    ->whereHas('aiAssistant', function ($q) {
                        $q->withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                            ->where('status', UserStatus::ACTIVE->value);
                    })
                    ->with(['aiAssistant' => function ($q) {
                        $q->withoutGlobalScope(\App\Scopes\OrganizationScope::class);
                    }]);
            }])
            ->where('id', $aiLoadBalancerId)
            ->where('organization_id', $extension->organization_id)
            ->where('status', AlbsStatus::ACTIVE)
            ->first();

        if (! $aiLoadBalancer) {
            Log::error('AiLoadBalancerRoutingStrategy: AI Load Balancer not found or inactive', [
                'ai_load_balancer_id' => $aiLoadBalancerId,
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return response(
                CxmlBuilder::unavailable('AI Load Balancer not found'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        return $this->routeWithLoadBalancer($request, $aiLoadBalancer);
    }

    /**
     * Route call using a loaded AI Load Balancer.
     *
     * @param  Request  $request  The incoming request
     * @param  AiAssistantLoadBalancer  $aiLoadBalancer  The ALB to route to
     * @return Response CXML response
     */
    private function routeWithLoadBalancer(Request $request, AiAssistantLoadBalancer $aiLoadBalancer): Response
    {
        if ($aiLoadBalancer->status->value !== 'active') {
            Log::warning('AiLoadBalancerRoutingStrategy: AI Load Balancer is inactive', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
                'ai_load_balancer_name' => $aiLoadBalancer->name,
                'status' => $aiLoadBalancer->status->value,
            ]);

            return $this->handleFallback($aiLoadBalancer, $request);
        }

        Log::info('AiLoadBalancerRoutingStrategy: Routing to AI Load Balancer', [
            'ai_load_balancer_id' => $aiLoadBalancer->id,
            'ai_load_balancer_name' => $aiLoadBalancer->name,
            'strategy' => $aiLoadBalancer->strategy->value,
        ]);

        $selectedAiAssistant = $this->distributionService->selectAssistant($aiLoadBalancer);

        if (! $selectedAiAssistant) {
            Log::warning('AiLoadBalancerRoutingStrategy: No AI Assistant selected', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
                'strategy' => $aiLoadBalancer->strategy->value,
            ]);

            return $this->handleFallback($aiLoadBalancer, $request);
        }

        Log::info('AiLoadBalancerRoutingStrategy: Selected AI Assistant', [
            'ai_load_balancer_id' => $aiLoadBalancer->id,
            'selected_ai_assistant_id' => $selectedAiAssistant->id,
            'selected_ai_assistant_name' => $selectedAiAssistant->name,
        ]);

        return $this->routeToAiAssistant($selectedAiAssistant, $aiLoadBalancer, $request);
    }

    private function routeToAiAssistant(AiAssistant $aiAssistant, AiAssistantLoadBalancer $aiLoadBalancer, Request $request): Response
    {
        $config = $aiAssistant->configuration ?? [];
        $protocol = $aiAssistant->protocol;
        $provider = $aiAssistant->provider;

        if ($protocol === 'websocket') {
            return $this->routeWebSocket($aiAssistant, $aiLoadBalancer, $config, $provider, $request);
        }

        return $this->routeSip($aiAssistant, $aiLoadBalancer, $config, $provider, $request);
    }

    private function routeWebSocket(AiAssistant $aiAssistant, AiAssistantLoadBalancer $aiLoadBalancer, array $config, ?string $provider, Request $request): Response
    {
        if (! $provider) {
            Log::error('AiLoadBalancerRoutingStrategy: WebSocket AI Assistant missing provider', [
                'ai_assistant_id' => $aiAssistant->id,
                'ai_assistant_name' => $aiAssistant->name,
            ]);

            return response(
                CxmlBuilder::unavailable('AI Assistant provider not configured'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        $providerDef = $this->providerRegistry->getProvider($provider);

        if (! $providerDef || ! $providerDef->isWebSocketProvider()) {
            Log::error('AiLoadBalancerRoutingStrategy: Invalid WebSocket provider', [
                'ai_assistant_id' => $aiAssistant->id,
                'ai_assistant_name' => $aiAssistant->name,
                'provider' => $provider,
            ]);

            return response(
                CxmlBuilder::unavailable('Invalid AI Assistant provider configuration'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        $cloudonixParams = [
            'session' => $request->input('CallSid', ''),
            'from' => $request->input('From', ''),
            'to' => $request->input('To', ''),
        ];

        try {
            $websocketUrl = $this->urlBuilder->buildUrl(
                $providerDef->urlTemplate,
                $config,
                $cloudonixParams
            );

            Log::info('AiLoadBalancerRoutingStrategy: Routing to WebSocket AI provider', [
                'ai_assistant_id' => $aiAssistant->id,
                'ai_assistant_name' => $aiAssistant->name,
                'provider' => $provider,
                'call_sid' => $cloudonixParams['session'],
            ]);

            // Generate callback URL for follow-through when call fails
            // Using action parameter on Connect verb for callback
            $callbackUrl = $this->getFollowThroughCallbackUrl($aiLoadBalancer, $aiAssistant, $request);

            return response(
                CxmlBuilder::streamToWebSocketWithAction($websocketUrl, $callbackUrl),
                200,
                ['Content-Type' => 'text/xml']
            );
        } catch (\InvalidArgumentException $e) {
            Log::error('AiLoadBalancerRoutingStrategy: Failed to build WebSocket URL', [
                'ai_assistant_id' => $aiAssistant->id,
                'ai_assistant_name' => $aiAssistant->name,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response(
                CxmlBuilder::unavailable('AI Assistant configuration error'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }
    }

    private function routeSip(AiAssistant $aiAssistant, AiAssistantLoadBalancer $aiLoadBalancer, array $config, ?string $provider, Request $request): Response
    {
        $phoneNumber = $config['phone_number'] ?? null;

        if (! $provider || ! $phoneNumber) {
            Log::error('AiLoadBalancerRoutingStrategy: SIP AI Assistant missing provider or phone number', [
                'ai_assistant_id' => $aiAssistant->id,
                'ai_assistant_name' => $aiAssistant->name,
                'has_provider' => $provider !== null,
                'has_phone_number' => $phoneNumber !== null,
            ]);

            return response(
                CxmlBuilder::unavailable('AI Agent provider or phone number not configured'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        Log::info('AiLoadBalancerRoutingStrategy: Routing to SIP AI provider', [
            'ai_assistant_id' => $aiAssistant->id,
            'ai_assistant_name' => $aiAssistant->name,
            'provider' => $provider,
            'phone_number' => $phoneNumber,
        ]);

        // Generate callback URL for follow-through when call fails
        $callbackUrl = $this->getFollowThroughCallbackUrl($aiLoadBalancer, $aiAssistant, $request);

        return response(
            CxmlBuilder::dialServiceProviderWithAction($provider, $phoneNumber, $callbackUrl),
            200,
            ['Content-Type' => 'text/xml']
        );
    }

    private function handleFallback(AiAssistantLoadBalancer $aiLoadBalancer, Request $request): Response
    {
        $fallbackAction = $aiLoadBalancer->fallback_action;

        Log::info('AiLoadBalancerRoutingStrategy: Executing fallback action', [
            'ai_load_balancer_id' => $aiLoadBalancer->id,
            'ai_load_balancer_name' => $aiLoadBalancer->name,
            'fallback_action' => $fallbackAction->value,
        ]);

        return match ($fallbackAction) {
            RingGroupFallbackAction::EXTENSION => $this->handleFallbackExtension($aiLoadBalancer, $request),
            RingGroupFallbackAction::RING_GROUP => $this->handleFallbackRingGroup($aiLoadBalancer, $request),
            RingGroupFallbackAction::IVR_MENU => $this->handleFallbackIvrMenu($aiLoadBalancer, $request),
            RingGroupFallbackAction::AI_ASSISTANT => $this->handleFallbackAiAssistant($aiLoadBalancer, $request),
            RingGroupFallbackAction::HANGUP => $this->handleFallbackHangup($aiLoadBalancer),
            default => $this->handleFallbackHangup($aiLoadBalancer),
        };
    }

    private function handleFallbackExtension(AiAssistantLoadBalancer $aiLoadBalancer, Request $request): Response
    {
        $fallbackExtensionId = $aiLoadBalancer->fallback_extension_id;

        if (! $fallbackExtensionId) {
            Log::warning('AiLoadBalancerRoutingStrategy: No fallback extension configured', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
            ]);

            return $this->handleFallbackHangup($aiLoadBalancer);
        }

        $fallbackExtension = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackExtensionId)
            ->where('organization_id', $aiLoadBalancer->organization_id)
            ->first();

        if (! $fallbackExtension || ! $fallbackExtension->isActive()) {
            Log::warning('AiLoadBalancerRoutingStrategy: Fallback extension not found or inactive', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
                'fallback_extension_id' => $fallbackExtensionId,
            ]);

            return $this->handleFallbackHangup($aiLoadBalancer);
        }

        Log::info('AiLoadBalancerRoutingStrategy: Routing to fallback extension', [
            'ai_load_balancer_id' => $aiLoadBalancer->id,
            'fallback_extension_id' => $fallbackExtension->id,
            'fallback_extension_number' => $fallbackExtension->extension_number,
        ]);

        $destination = ['extension' => $fallbackExtension];

        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy(
            $fallbackExtension->type,
            $request,
            new DidNumber,
            $destination
        );
    }

    private function handleFallbackRingGroup(AiAssistantLoadBalancer $aiLoadBalancer, Request $request): Response
    {
        $fallbackRingGroupId = $aiLoadBalancer->fallback_ring_group_id;

        if (! $fallbackRingGroupId) {
            Log::warning('AiLoadBalancerRoutingStrategy: No fallback ring group configured', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
            ]);

            return $this->handleFallbackHangup($aiLoadBalancer);
        }

        $fallbackRingGroup = RingGroup::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackRingGroupId)
            ->where('organization_id', $aiLoadBalancer->organization_id)
            ->first();

        if (! $fallbackRingGroup || ! $fallbackRingGroup->isActive()) {
            Log::warning('AiLoadBalancerRoutingStrategy: Fallback ring group not found or inactive', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
                'fallback_ring_group_id' => $fallbackRingGroupId,
            ]);

            return $this->handleFallbackHangup($aiLoadBalancer);
        }

        Log::info('AiLoadBalancerRoutingStrategy: Routing to fallback ring group', [
            'ai_load_balancer_id' => $aiLoadBalancer->id,
            'fallback_ring_group_id' => $fallbackRingGroup->id,
            'fallback_ring_group_name' => $fallbackRingGroup->name,
        ]);

        $destination = ['ring_group' => $fallbackRingGroup];

        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy(
            ExtensionType::RING_GROUP,
            $request,
            new DidNumber,
            $destination
        );
    }

    private function handleFallbackIvrMenu(AiAssistantLoadBalancer $aiLoadBalancer, Request $request): Response
    {
        $fallbackIvrMenuId = $aiLoadBalancer->fallback_ivr_menu_id;

        if (! $fallbackIvrMenuId) {
            Log::warning('AiLoadBalancerRoutingStrategy: No fallback IVR menu configured', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
            ]);

            return $this->handleFallbackHangup($aiLoadBalancer);
        }

        $fallbackIvrMenu = IvrMenu::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackIvrMenuId)
            ->where('organization_id', $aiLoadBalancer->organization_id)
            ->first();

        if (! $fallbackIvrMenu || ! $fallbackIvrMenu->isActive()) {
            Log::warning('AiLoadBalancerRoutingStrategy: Fallback IVR menu not found or inactive', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
                'fallback_ivr_menu_id' => $fallbackIvrMenuId,
            ]);

            return $this->handleFallbackHangup($aiLoadBalancer);
        }

        Log::info('AiLoadBalancerRoutingStrategy: Routing to fallback IVR menu', [
            'ai_load_balancer_id' => $aiLoadBalancer->id,
            'fallback_ivr_menu_id' => $fallbackIvrMenu->id,
            'fallback_ivr_menu_name' => $fallbackIvrMenu->name,
        ]);

        $destination = ['ivr_menu' => $fallbackIvrMenu];

        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy(
            ExtensionType::IVR,
            $request,
            new DidNumber,
            $destination
        );
    }

    private function handleFallbackAiAssistant(AiAssistantLoadBalancer $aiLoadBalancer, Request $request): Response
    {
        $fallbackAiAssistantId = $aiLoadBalancer->fallback_ai_assistant_id;

        if (! $fallbackAiAssistantId) {
            Log::warning('AiLoadBalancerRoutingStrategy: No fallback AI Assistant configured', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
            ]);

            return $this->handleFallbackHangup($aiLoadBalancer);
        }

        $fallbackAiAssistant = AiAssistant::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackAiAssistantId)
            ->where('organization_id', $aiLoadBalancer->organization_id)
            ->first();

        if (! $fallbackAiAssistant || $fallbackAiAssistant->status->value !== 'active') {
            Log::warning('AiLoadBalancerRoutingStrategy: Fallback AI Assistant not found or inactive', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
                'fallback_ai_assistant_id' => $fallbackAiAssistantId,
            ]);

            return $this->handleFallbackHangup($aiLoadBalancer);
        }

        Log::info('AiLoadBalancerRoutingStrategy: Routing to fallback AI Assistant', [
            'ai_load_balancer_id' => $aiLoadBalancer->id,
            'fallback_ai_assistant_id' => $fallbackAiAssistant->id,
            'fallback_ai_assistant_name' => $fallbackAiAssistant->name,
        ]);

        return $this->routeToAiAssistant($fallbackAiAssistant, $aiLoadBalancer, $request);
    }

    private function handleFallbackHangup(AiAssistantLoadBalancer $aiLoadBalancer): Response
    {
        Log::info('AiLoadBalancerRoutingStrategy: Hanging up call', [
            'ai_load_balancer_id' => $aiLoadBalancer->id,
        ]);

        return response(
            CxmlBuilder::unavailable('No AI assistants available. Goodbye.'),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
