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
    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::RING_GROUP;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        /** @var RingGroup $ringGroup */
        $ringGroup = $destination['ring_group'] ?? null;

        if (!$ringGroup) {
            return response(CxmlBuilder::unavailable('Ring group not found'), 200, ['Content-Type' => 'text/xml']);
        }

        if (!$ringGroup->isActive()) {
            return response(CxmlBuilder::unavailable('Ring group is inactive'), 200, ['Content-Type' => 'text/xml']);
        }

        // Check if this is a callback (subsequent attempt) or initial call
        $attempt = (int) $request->input('SessionData.attempt_number', 0);

        // If attempt number is not in SessionData, try to get it from query params or session_data
        if ($attempt === 0) {
            $attempt = (int) $request->input('attempt_number', 0);
            if ($attempt === 0) {
                // Try to extract from session_data JSON
                $sessionDataJson = $request->input('session_data');
                if ($sessionDataJson) {
                    $sessionData = json_decode($sessionDataJson, true);
                    $attempt = (int) ($sessionData['attempt_number'] ?? 0);
                }
            }
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
            $targets[] = $member->extension_number;
        }

        return response(
            CxmlBuilder::dialRingGroup($targets, $ringGroup->timeout ?? 30),
            200,
            ['Content-Type' => 'text/xml']
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

        if (!$member) {
            return $this->handleFallback($ringGroup, $request);
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

        $baseUrl = $cloudonixSettings && $cloudonixSettings->webhook_base_url
            ? rtrim($cloudonixSettings->webhook_base_url, '/')
            : $request->getSchemeAndHttpHost();

        $relativeUrl = route('voice.ring-group-callback', [
            'ring_group_id' => $ringGroup->id,
            'attempt_number' => $nextAttempt,
            // Pass necessary context
            'session_data' => json_encode(['ring_group_id' => $ringGroup->id, 'attempt_number' => $nextAttempt])
        ], false); // Get relative URL
        $callbackUrl = $baseUrl . $relativeUrl;

        // Also need to construct SessionData xml element if strictly required, 
        // but CxmlBuilder -> dial(...) takes 'action'. 

        $builder = new CxmlBuilder();
        $builder->dial(
            $sipUri,
            $ringGroup->timeout ?? 20,
            $callbackUrl
        );

        return $builder->toResponse();
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

        if (!$fallbackExtensionId) {
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

        if (!$fallbackExtension) {
            Log::warning('RingGroupRoutingStrategy: Fallback extension not found', [
                'ring_group_id' => $ringGroup->id,
                'ring_group_name' => $ringGroup->name,
                'fallback_extension_id' => $fallbackExtensionId,
            ]);
            return $this->handleFallbackHangup($ringGroup);
        }

        if (!$fallbackExtension->isActive()) {
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

        return $voiceRoutingManager->executeStrategy($fallbackExtension->type, $request, new \App\Models\DidNumber(), $destination);
    }

    private function handleFallbackRingGroup(RingGroup $ringGroup, Request $request): Response
    {
        $fallbackRingGroupId = $ringGroup->fallback_ring_group_id;

        if (!$fallbackRingGroupId) {
            return $this->handleFallbackHangup($ringGroup);
        }

        $fallbackRingGroup = RingGroup::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackRingGroupId)
            ->where('organization_id', $ringGroup->organization_id)
            ->first();

        if (!$fallbackRingGroup || !$fallbackRingGroup->isActive()) {
            return $this->handleFallbackHangup($ringGroup);
        }

        // Use the same routing logic as normal ring group dialing
        $destination = ['ring_group' => $fallbackRingGroup];

        // Get the VoiceRoutingManager instance and delegate to it
        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy(\App\Enums\ExtensionType::RING_GROUP, $request, new \App\Models\DidNumber(), $destination);
    }

    private function handleFallbackIvrMenu(RingGroup $ringGroup, Request $request): Response
    {
        $fallbackIvrMenuId = $ringGroup->fallback_ivr_menu_id;

        if (!$fallbackIvrMenuId) {
            return $this->handleFallbackHangup($ringGroup);
        }

        $fallbackIvrMenu = IvrMenu::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackIvrMenuId)
            ->where('organization_id', $ringGroup->organization_id)
            ->first();

        if (!$fallbackIvrMenu || !$fallbackIvrMenu->isActive()) {
            return $this->handleFallbackHangup($ringGroup);
        }

        // Use the same routing logic as normal IVR menu dialing
        $destination = ['ivr_menu' => $fallbackIvrMenu];

        // Get the VoiceRoutingManager instance and delegate to it
        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy(\App\Enums\ExtensionType::IVR, $request, new \App\Models\DidNumber(), $destination);
    }

    private function handleFallbackAiAssistant(RingGroup $ringGroup, Request $request): Response
    {
        $fallbackAiAssistantId = $ringGroup->fallback_ai_assistant_id;

        if (!$fallbackAiAssistantId) {
            return $this->handleFallbackHangup($ringGroup);
        }

        $fallbackAiAssistant = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $fallbackAiAssistantId)
            ->where('organization_id', $ringGroup->organization_id)
            ->where('type', ExtensionType::AI_ASSISTANT)
            ->first();

        if (!$fallbackAiAssistant || !$fallbackAiAssistant->isActive()) {
            return $this->handleFallbackHangup($ringGroup);
        }

        // Use the same routing logic as normal AI assistant dialing
        $destination = ['extension' => $fallbackAiAssistant];

        // Get the VoiceRoutingManager instance and delegate to it
        $voiceRoutingManager = app(\App\Services\VoiceRouting\VoiceRoutingManager::class);

        return $voiceRoutingManager->executeStrategy($fallbackAiAssistant->type, $request, new \App\Models\DidNumber(), $destination);
    }

    private function handleFallbackHangup(RingGroup $ringGroup): Response
    {
        // Default fallback: hangup with message
        return response(
            CxmlBuilder::unavailable('No agents available. Goodbye.'),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
