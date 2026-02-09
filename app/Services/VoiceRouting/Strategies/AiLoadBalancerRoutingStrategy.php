<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Models\AiAssistantLoadBalancer;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\AlbsDistributionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Routing strategy for AI Load Balancer extension type.
 *
 * Routes calls to an AI Assistant Load Balancer, which uses distribution
 * algorithms (Round Robin, Priority, Percentage) to select an AI Assistant.
 */
class AiLoadBalancerRoutingStrategy implements RoutingStrategy
{
    public function __construct(
        private readonly AlbsDistributionService $distributionService
    ) {}

    /**
     * Check if this strategy can handle the given extension type.
     */
    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::AI_LOAD_BALANCER;
    }

    /**
     * Route the call to an AI Load Balancer.
     *
     * Uses the distribution service to select an AI Assistant, then
     * delegates to AiAgentRoutingStrategy to generate connection CXML.
     */
    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        $extension = $destination['extension'] ?? null;

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

        // Load AI Load Balancer from extension configuration
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

        // Load the AI Load Balancer
        $aiLoadBalancer = AiAssistantLoadBalancer::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $aiLoadBalancerId)
            ->where('organization_id', $extension->organization_id)
            ->first();

        if (! $aiLoadBalancer) {
            Log::error('AiLoadBalancerRoutingStrategy: AI Load Balancer not found', [
                'extension_id' => $extension->id,
                'ai_load_balancer_id' => $aiLoadBalancerId,
            ]);

            return response(
                CxmlBuilder::unavailable('AI Load Balancer not found'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        // Check if load balancer is active
        if ($aiLoadBalancer->status->value !== 'active') {
            Log::warning('AiLoadBalancerRoutingStrategy: AI Load Balancer is inactive', [
                'extension_id' => $extension->id,
                'ai_load_balancer_id' => $aiLoadBalancer->id,
                'status' => $aiLoadBalancer->status->value,
            ]);

            return $this->handleFallback($aiLoadBalancer, $request);
        }

        Log::info('AiLoadBalancerRoutingStrategy: Routing to AI Load Balancer', [
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
            'ai_load_balancer_id' => $aiLoadBalancer->id,
            'ai_load_balancer_name' => $aiLoadBalancer->name,
            'strategy' => $aiLoadBalancer->strategy->value,
        ]);

        // Use distribution service to select an AI Assistant
        $selectedAiAssistant = $this->distributionService->selectAssistant($aiLoadBalancer);

        if (! $selectedAiAssistant) {
            Log::warning('AiLoadBalancerRoutingStrategy: No AI Assistant selected', [
                'extension_id' => $extension->id,
                'ai_load_balancer_id' => $aiLoadBalancer->id,
                'strategy' => $aiLoadBalancer->strategy->value,
            ]);

            return $this->handleFallback($aiLoadBalancer, $request);
        }

        // Find the extension for the selected AI Assistant
        $aiExtension = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('ai_assistant_id', $selectedAiAssistant->id)
            ->where('organization_id', $extension->organization_id)
            ->where('status', 'active')
            ->first();

        if (! $aiExtension) {
            Log::warning('AiLoadBalancerRoutingStrategy: Selected AI Assistant extension not found or inactive', [
                'extension_id' => $extension->id,
                'ai_load_balancer_id' => $aiLoadBalancer->id,
                'ai_assistant_id' => $selectedAiAssistant->id,
            ]);

            return $this->handleFallback($aiLoadBalancer, $request);
        }

        Log::info('AiLoadBalancerRoutingStrategy: Selected AI Assistant, delegating to AiAgentRoutingStrategy', [
            'extension_id' => $extension->id,
            'ai_load_balancer_id' => $aiLoadBalancer->id,
            'selected_ai_assistant_id' => $selectedAiAssistant->id,
            'selected_ai_assistant_name' => $selectedAiAssistant->name,
            'ai_extension_id' => $aiExtension->id,
        ]);

        // Delegate to AiAgentRoutingStrategy
        $destination = ['extension' => $aiExtension];

        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy(
            ExtensionType::AI_ASSISTANT,
            $request,
            $did,
            $destination
        );
    }

    /**
     * Handle fallback action when no AI Assistant can be selected.
     */
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

    /**
     * Handle fallback to an extension.
     */
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

    /**
     * Handle fallback to a ring group.
     */
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

    /**
     * Handle fallback to an IVR menu.
     */
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

    /**
     * Handle fallback to an AI Assistant.
     */
    private function handleFallbackAiAssistant(AiAssistantLoadBalancer $aiLoadBalancer, Request $request): Response
    {
        $fallbackAiAssistantId = $aiLoadBalancer->fallback_ai_assistant_id;

        if (! $fallbackAiAssistantId) {
            Log::warning('AiLoadBalancerRoutingStrategy: No fallback AI Assistant configured', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
            ]);

            return $this->handleFallbackHangup($aiLoadBalancer);
        }

        $fallbackAiAssistant = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackAiAssistantId)
            ->where('organization_id', $aiLoadBalancer->organization_id)
            ->where('type', ExtensionType::AI_ASSISTANT)
            ->first();

        if (! $fallbackAiAssistant || ! $fallbackAiAssistant->isActive()) {
            Log::warning('AiLoadBalancerRoutingStrategy: Fallback AI Assistant not found or inactive', [
                'ai_load_balancer_id' => $aiLoadBalancer->id,
                'fallback_ai_assistant_id' => $fallbackAiAssistantId,
            ]);

            return $this->handleFallbackHangup($aiLoadBalancer);
        }

        Log::info('AiLoadBalancerRoutingStrategy: Routing to fallback AI Assistant', [
            'ai_load_balancer_id' => $aiLoadBalancer->id,
            'fallback_ai_assistant_id' => $fallbackAiAssistant->id,
            'fallback_ai_assistant_number' => $fallbackAiAssistant->extension_number,
        ]);

        $destination = ['extension' => $fallbackAiAssistant];

        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy(
            $fallbackAiAssistant->type,
            $request,
            new DidNumber,
            $destination
        );
    }

    /**
     * Handle fallback hangup.
     */
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
