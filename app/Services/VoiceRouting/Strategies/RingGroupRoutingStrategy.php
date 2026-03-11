<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Enums\RingGroupStrategy as StrategyEnum;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class RingGroupRoutingStrategy implements RoutingStrategy
{
    /**
     * Generate a callback URL for ring group follow-through/fallback.
     *
     * When all ring group members fail or don't answer, Cloudonix will call this URL
     * which will trigger the fallback action configured for the ring group.
     *
     * @param  RingGroup  $ringGroup  The ring group
     * @param  Request  $request  The original request
     * @param  int  $attemptNumber  The attempt number (for sequential routing)
     * @return string The full callback URL
     */
    private function getRingGroupCallbackUrl(RingGroup $ringGroup, Request $request, int $attemptNumber = 0): string
    {
        $organizationId = $request->input('_organization_id');
        $cloudonixSettings = CloudonixSettings::where('organization_id', $organizationId)->first();

        $baseUrl = $cloudonixSettings
            ? rtrim($cloudonixSettings->effective_webhook_base_url ?? config('app.url'), '/')
            : rtrim(config('app.url'), '/');

        // Build session data with ring group context
        $sessionData = [
            'ring_group_id' => $ringGroup->id,
            'attempt_number' => $attemptNumber,
            'organization_id' => $ringGroup->organization_id,
            'callback_type' => 'ring_group_fallback',
        ];

        $relativeUrl = route('voice.ring-group-callback', [
            'ring_group_id' => $ringGroup->id,
            'attempt_number' => $attemptNumber,
            'session_data' => json_encode($sessionData),
        ], false);

        return $baseUrl.$relativeUrl;
    }

    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::RING_GROUP;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        /** @var RingGroup $ringGroup */
        $ringGroup = $destination['ring_group'] ?? null;

        if (! $ringGroup) {
            return response(CxmlBuilder::unavailable('Ring group not found'), 200, ['Content-Type' => 'application/xml']);
        }

        if (! $ringGroup->isActive()) {
            return response(CxmlBuilder::unavailable('Ring group is inactive'), 200, ['Content-Type' => 'application/xml']);
        }

        // Check if this is a callback (subsequent attempt) or initial call
        $attempt = (int) $request->input('SessionData.attempt_number', 0);
        $callbackType = $request->input('SessionData.callback_type');

        // If attempt number is not in SessionData, try to get it from query params or session_data
        if ($attempt === 0) {
            $attempt = (int) $request->input('attempt_number', 0);
            if ($attempt === 0) {
                // Try to extract from session_data JSON
                $sessionDataJson = $request->input('session_data');
                if ($sessionDataJson) {
                    $sessionData = json_decode($sessionDataJson, true);
                    $attempt = (int) ($sessionData['attempt_number'] ?? 0);
                    $callbackType = $sessionData['callback_type'] ?? $callbackType;
                }
            }
        }

        // For simultaneous ring groups, if this is a callback (all targets failed),
        // immediately trigger fallback instead of re-dialing
        if ($ringGroup->strategy === StrategyEnum::SIMULTANEOUS && $callbackType === 'ring_group_fallback') {
            Log::info('RingGroupRoutingStrategy: Simultaneous ring callback received, triggering fallback', [
                'ring_group_id' => $ringGroup->id,
                'ring_group_name' => $ringGroup->name,
                'attempt' => $attempt,
            ]);

            return $this->handleFallback($ringGroup, $request);
        }

        if ($ringGroup->strategy === StrategyEnum::SIMULTANEOUS) {
            return $this->handleSimultaneous($ringGroup, $request);
        }

        return $this->handleSequential($ringGroup, $request, $attempt);
    }

    private function handleSimultaneous(RingGroup $ringGroup, Request $request): Response
    {
        $members = $ringGroup->getMembers();
        if ($members->isEmpty()) {
            return $this->handleFallback($ringGroup, $request);
        }

        $targets = [];
        foreach ($members as $member) {
            // Handle forward extensions by routing to their configured destination
            if ($member->type === ExtensionType::FORWARD) {
                $forwardTo = $member->configuration['forward_to'] ?? null;

                if (! $forwardTo) {
                    Log::warning('RingGroupRoutingStrategy: Forward extension has no destination configured', [
                        'ring_group_id' => $ringGroup->id,
                        'member_extension_number' => $member->extension_number,
                    ]);

                    continue;
                }

                // Route to the forward destination (phone number, SIP URI, or extension)
                $targets[] = $forwardTo;

                Log::info('RingGroupRoutingStrategy: Including forward extension destination in simultaneous ring group', [
                    'ring_group_id' => $ringGroup->id,
                    'member_extension_number' => $member->extension_number,
                    'forward_to' => $forwardTo,
                ]);

                continue;
            }

            $targets[] = $member->extension_number;
        }

        // If no valid targets found, fallback
        if (empty($targets)) {
            Log::warning('RingGroupRoutingStrategy: No valid targets found for simultaneous ring group', [
                'ring_group_id' => $ringGroup->id,
                'original_member_count' => $members->count(),
                'forward_extensions_count' => $members->where('type', ExtensionType::FORWARD)->count(),
            ]);

            return $this->handleFallback($ringGroup, $request);
        }

        // Generate callback URL for fallback handling
        // When all targets fail, Cloudonix will call this URL to trigger fallback
        $callbackUrl = $this->getRingGroupCallbackUrl($ringGroup, $request, 0);

        Log::info('RingGroupRoutingStrategy: Initiating simultaneous ring with fallback callback', [
            'ring_group_id' => $ringGroup->id,
            'ring_group_name' => $ringGroup->name,
            'target_count' => count($targets),
            'timeout' => $ringGroup->timeout ?? 30,
            'callback_url' => $callbackUrl,
        ]);

        return response(
            CxmlBuilder::dialRingGroup($targets, $ringGroup->timeout ?? 30, $callbackUrl),
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    private function handleSequential(RingGroup $ringGroup, Request $request, int $attempt): Response
    {
        // Get all members
        $members = $ringGroup->getMembers();

        // If no members, fallback
        if ($members->isEmpty()) {
            return $this->handleFallback($ringGroup, $request);
        }

        // Get ring turns (default to 1 if not set)
        $ringTurns = max(1, $ringGroup->ring_turns ?? 1);
        $memberCount = $members->count();
        $totalAttemptsAllowed = $memberCount * $ringTurns;

        // If we've exhausted all ring turns, fallback
        if ($attempt >= $totalAttemptsAllowed) {
            Log::info('RingGroupRoutingStrategy: All ring attempts exhausted, triggering fallback', [
                'ring_group_id' => $ringGroup->id,
                'ring_group_name' => $ringGroup->name,
                'total_members' => $memberCount,
                'ring_turns' => $ringTurns,
                'total_attempts_allowed' => $totalAttemptsAllowed,
                'final_attempt' => $attempt,
                'fallback_action' => $ringGroup->fallback_action->value,
            ]);

            return $this->handleFallback($ringGroup, $request);
        }

        // Calculate which member to try in this attempt
        // Using modulo to cycle through members for each ring turn
        $memberIndex = $attempt % $memberCount;

        // Get the specific member
        $member = $members->values()->get($memberIndex);

        if (! $member) {
            return $this->handleFallback($ringGroup, $request);
        }

        // Check if member is a forward extension
        if ($member->type === ExtensionType::FORWARD) {
            return $this->handleMemberForwardExtension($ringGroup, $member, $request, $attempt);
        }

        $sipUri = $member->extension_number;

        // Build Action URL for next attempt
        // We need a URL that points back to the VoiceRoutingController callback handler
        // which will delegate back to this strategy via Manager.
        // Assuming /api/voice/callback/ring-group endpoint
        // And we need to pass state data.

        // Cloudonix CXML Dial Action supports params? Usually as URL query params.
        // But CxmlBuilder uses 'action' attribute.

        $nextAttempt = $attempt + 1;

        // Get the organization's webhook base URL for consistent callback URLs
        $organizationId = $request->input('_organization_id');
        $cloudonixSettings = CloudonixSettings::where('organization_id', $organizationId)->first();

        $baseUrl = $cloudonixSettings
            ? rtrim($cloudonixSettings->effective_webhook_base_url ?? config('app.url'), '/')
            : rtrim(config('app.url'), '/');

        $relativeUrl = route('voice.ring-group-callback', [
            'ring_group_id' => $ringGroup->id,
            'attempt_number' => $nextAttempt,
            // Pass necessary context
            'session_data' => json_encode(['ring_group_id' => $ringGroup->id, 'attempt_number' => $nextAttempt]),
        ], false); // Get relative URL
        $callbackUrl = $baseUrl.$relativeUrl;

        // Also need to construct SessionData xml element if strictly required,
        // but CxmlBuilder -> dial(...) takes 'action'.

        $builder = new CxmlBuilder;
        $builder->dial(
            $sipUri,
            $ringGroup->timeout ?? 20,
            $callbackUrl
        );

        return $builder->toResponse();
    }

    /**
     * Handle a forward extension member in sequential ring group.
     *
     * For forward extensions, we need to route to the configured forward destination
     * rather than trying to dial the extension number itself.
     */
    private function handleMemberForwardExtension(RingGroup $ringGroup, Extension $member, Request $request, int $attempt): Response
    {
        $config = $member->configuration ?? [];
        $forwardTo = $config['forward_to'] ?? null;

        Log::info('RingGroupRoutingStrategy: Handling forward extension member', [
            'ring_group_id' => $ringGroup->id,
            'member_extension_number' => $member->extension_number,
            'forward_to' => $forwardTo,
            'attempt' => $attempt,
        ]);

        if (! $forwardTo) {
            Log::warning('RingGroupRoutingStrategy: Forward extension has no forward destination configured', [
                'ring_group_id' => $ringGroup->id,
                'member_extension_number' => $member->extension_number,
            ]);

            // Try next member or fallback
            return $this->tryNextMemberOrFallback($ringGroup, $request, $attempt);
        }

        // Generate callback URL for fallback/next attempt handling
        $nextAttempt = $attempt + 1;
        $callbackUrl = $this->getRingGroupCallbackUrl($ringGroup, $request, $nextAttempt);

        // Check if forwardTo is a SIP URI
        if (str_starts_with(strtolower($forwardTo), 'sip:')) {
            // Dial the SIP URI directly with action callback
            $builder = new CxmlBuilder;
            $builder->dial($forwardTo, $ringGroup->timeout ?? 20, $callbackUrl);

            return $builder->toResponse();
        }

        // Check if forwardTo is an E.164 phone number
        if (preg_match('/^\+[1-9]\d{1,14}$/', $forwardTo)) {
            // Dial the phone number directly with action callback
            $builder = new CxmlBuilder;
            $builder->dial($forwardTo, $ringGroup->timeout ?? 20, $callbackUrl);

            return $builder->toResponse();
        }

        // Assume forwardTo is an internal extension number - route to it
        $targetExtension = Extension::where('organization_id', $ringGroup->organization_id)
            ->where('extension_number', $forwardTo)
            ->first();

        if (! $targetExtension) {
            Log::warning('RingGroupRoutingStrategy: Forward destination extension not found', [
                'ring_group_id' => $ringGroup->id,
                'member_extension_number' => $member->extension_number,
                'forward_to' => $forwardTo,
            ]);

            return $this->tryNextMemberOrFallback($ringGroup, $request, $attempt);
        }

        if (! $targetExtension->isActive()) {
            Log::warning('RingGroupRoutingStrategy: Forward destination extension is inactive', [
                'ring_group_id' => $ringGroup->id,
                'member_extension_number' => $member->extension_number,
                'forward_to' => $forwardTo,
            ]);

            return $this->tryNextMemberOrFallback($ringGroup, $request, $attempt);
        }

        // Route to the target extension using proper routing strategy
        $destination = ['extension' => $targetExtension];
        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy($targetExtension->type, $request, new \App\Models\DidNumber, $destination);
    }

    /**
     * Try the next member in sequential routing or fallback if no more members.
     */
    private function tryNextMemberOrFallback(RingGroup $ringGroup, Request $request, int $attempt): Response
    {
        $members = $ringGroup->getMembers();
        $memberCount = $members->count();
        $nextAttempt = $attempt + 1;

        if ($nextAttempt >= $memberCount) {
            Log::info('RingGroupRoutingStrategy: No more members to try, falling back', [
                'ring_group_id' => $ringGroup->id,
                'current_attempt' => $attempt,
                'next_attempt' => $nextAttempt,
                'member_count' => $memberCount,
            ]);

            return $this->handleFallback($ringGroup, $request);
        }

        // Continue to next member
        return $this->handleSequential($ringGroup, $request, $nextAttempt);
    }

    private function handleFallback(RingGroup $ringGroup, Request $request): Response
    {
        $fallbackAction = $ringGroup->fallback_action;

        Log::info('RingGroupRoutingStrategy: Executing fallback action', [
            'ring_group_id' => $ringGroup->id,
            'ring_group_name' => $ringGroup->name,
            'fallback_action' => $fallbackAction->value,
        ]);

        return match ($fallbackAction) {
            RingGroupFallbackAction::EXTENSION => $this->handleFallbackExtension($ringGroup, $request),
            RingGroupFallbackAction::RING_GROUP => $this->handleFallbackRingGroup($ringGroup, $request),
            RingGroupFallbackAction::IVR_MENU => $this->handleFallbackIvrMenu($ringGroup, $request),
            RingGroupFallbackAction::AI_ASSISTANT => $this->handleFallbackAiAssistant($ringGroup, $request),
            RingGroupFallbackAction::AI_LOAD_BALANCER => $this->handleFallbackAiLoadBalancer($ringGroup, $request),
            RingGroupFallbackAction::HANGUP => $this->handleFallbackHangup($ringGroup),
            default => $this->handleFallbackHangup($ringGroup), // Default to hangup for unknown actions
        };
    }

    private function handleFallbackExtension(RingGroup $ringGroup, Request $request): Response
    {
        $fallbackExtensionId = $ringGroup->fallback_extension_id;

        Log::info('RingGroupRoutingStrategy: Attempting fallback to extension', [
            'ring_group_id' => $ringGroup->id,
            'ring_group_name' => $ringGroup->name,
            'fallback_extension_id' => $fallbackExtensionId,
        ]);

        if (! $fallbackExtensionId) {
            Log::warning('RingGroupRoutingStrategy: No fallback extension configured, using hangup', [
                'ring_group_id' => $ringGroup->id,
                'ring_group_name' => $ringGroup->name,
            ]);

            return $this->handleFallbackHangup($ringGroup);
        }

        // Instead of manual lookup and CXML generation, use the same routing logic as normal extensions
        // This ensures consistency and uses all the proper validation and routing strategies
        $fallbackExtension = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackExtensionId)
            ->where('organization_id', $ringGroup->organization_id)
            ->first();

        if (! $fallbackExtension) {
            Log::warning('RingGroupRoutingStrategy: Fallback extension not found', [
                'ring_group_id' => $ringGroup->id,
                'ring_group_name' => $ringGroup->name,
                'fallback_extension_id' => $fallbackExtensionId,
            ]);

            return $this->handleFallbackHangup($ringGroup);
        }

        if (! $fallbackExtension->isActive()) {
            Log::warning('RingGroupRoutingStrategy: Fallback extension is not active', [
                'ring_group_id' => $ringGroup->id,
                'ring_group_name' => $ringGroup->name,
                'fallback_extension_id' => $fallbackExtensionId,
                'extension_number' => $fallbackExtension->extension_number,
                'extension_status' => $fallbackExtension->status->value,
            ]);

            return $this->handleFallbackHangup($ringGroup);
        }

        Log::info('RingGroupRoutingStrategy: Routing to fallback extension using standard routing logic', [
            'ring_group_id' => $ringGroup->id,
            'ring_group_name' => $ringGroup->name,
            'fallback_extension_number' => $fallbackExtension->extension_number,
            'extension_type' => $fallbackExtension->type->value,
        ]);

        // Use the same routing logic as normal extension dialing
        // This ensures all extension types (user, ai_assistant, conference, etc.) work correctly
        $destination = ['extension' => $fallbackExtension];

        // Get the VoiceRoutingManager instance and delegate to it
        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy($fallbackExtension->type, $request, new \App\Models\DidNumber, $destination);
    }

    private function handleFallbackRingGroup(RingGroup $ringGroup, Request $request): Response
    {
        $fallbackRingGroupId = $ringGroup->fallback_ring_group_id;

        if (! $fallbackRingGroupId) {
            return $this->handleFallbackHangup($ringGroup);
        }

        $fallbackRingGroup = RingGroup::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackRingGroupId)
            ->where('organization_id', $ringGroup->organization_id)
            ->first();

        if (! $fallbackRingGroup || ! $fallbackRingGroup->isActive()) {
            return $this->handleFallbackHangup($ringGroup);
        }

        // Use the same routing logic as normal ring group dialing
        $destination = ['ring_group' => $fallbackRingGroup];

        // Get the VoiceRoutingManager instance and delegate to it
        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy(\App\Enums\ExtensionType::RING_GROUP, $request, new \App\Models\DidNumber, $destination);
    }

    private function handleFallbackIvrMenu(RingGroup $ringGroup, Request $request): Response
    {
        $fallbackIvrMenuId = $ringGroup->fallback_ivr_menu_id;

        if (! $fallbackIvrMenuId) {
            return $this->handleFallbackHangup($ringGroup);
        }

        $fallbackIvrMenu = IvrMenu::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackIvrMenuId)
            ->where('organization_id', $ringGroup->organization_id)
            ->first();

        if (! $fallbackIvrMenu || ! $fallbackIvrMenu->isActive()) {
            return $this->handleFallbackHangup($ringGroup);
        }

        // Use the same routing logic as normal IVR menu dialing
        $destination = ['ivr_menu' => $fallbackIvrMenu];

        // Get the VoiceRoutingManager instance and delegate to it
        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy(\App\Enums\ExtensionType::IVR, $request, new \App\Models\DidNumber, $destination);
    }

    private function handleFallbackAiAssistant(RingGroup $ringGroup, Request $request): Response
    {
        $fallbackAiAssistantId = $ringGroup->fallback_ai_assistant_id;

        if (! $fallbackAiAssistantId) {
            return $this->handleFallbackHangup($ringGroup);
        }

        $fallbackAiAssistant = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->with(['aiAssistant' => function ($query) {
                $query->withoutGlobalScope(\App\Scopes\OrganizationScope::class);
            }])
            ->where('id', $fallbackAiAssistantId)
            ->where('organization_id', $ringGroup->organization_id)
            ->where('type', ExtensionType::AI_ASSISTANT)
            ->first();

        if (! $fallbackAiAssistant || ! $fallbackAiAssistant->isActive()) {
            return $this->handleFallbackHangup($ringGroup);
        }

        // Use the same routing logic as normal AI assistant dialing
        $destination = ['extension' => $fallbackAiAssistant];

        // Get the VoiceRoutingManager instance and delegate to it
        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy($fallbackAiAssistant->type, $request, new \App\Models\DidNumber, $destination);
    }

    private function handleFallbackAiLoadBalancer(RingGroup $ringGroup, Request $request): Response
    {
        $fallbackAiLoadBalancerId = $ringGroup->fallback_ai_load_balancer_id;

        if (! $fallbackAiLoadBalancerId) {
            return $this->handleFallbackHangup($ringGroup);
        }

        $aiLoadBalancer = \App\Models\AiAssistantLoadBalancer::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackAiLoadBalancerId)
            ->where('organization_id', $ringGroup->organization_id)
            ->where('status', 'active')
            ->first();

        if (! $aiLoadBalancer) {
            return $this->handleFallbackHangup($ringGroup);
        }

        // Use the same routing logic as normal ALBS routing
        $destination = ['ai_load_balancer' => $aiLoadBalancer];

        // Get the VoiceRoutingManager instance and delegate to it
        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy(\App\Enums\ExtensionType::AI_LOAD_BALANCER, $request, new \App\Models\DidNumber, $destination);
    }

    private function handleFallbackHangup(RingGroup $ringGroup): Response
    {
        // Default fallback: hangup with message
        return response(
            CxmlBuilder::unavailable('No agents available. Goodbye.'),
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
