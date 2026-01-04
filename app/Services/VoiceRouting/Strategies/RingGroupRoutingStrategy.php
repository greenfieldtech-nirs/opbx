<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Enums\RingGroupStrategy as StrategyEnum;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\RingGroup;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
            return $this->handleFallback($ringGroup);
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
            return $this->handleFallback($ringGroup);
        }

        // Get ring turns (default to 1 if not set)
        $ringTurns = max(1, $ringGroup->ring_turns ?? 1);
        $memberCount = $members->count();
        $totalAttemptsAllowed = $memberCount * $ringTurns;

        // If we've exhausted all ring turns, fallback
        if ($attempt >= $totalAttemptsAllowed) {
            return $this->handleFallback($ringGroup);
        }

        // Calculate which member to try in this attempt
        // Using modulo to cycle through members for each ring turn
        $memberIndex = $attempt % $memberCount;

        // Get the specific member
        $member = $members->values()->get($memberIndex);

        if (!$member) {
            return $this->handleFallback($ringGroup);
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

    private function handleFallback(RingGroup $ringGroup): Response
    {
        $fallbackAction = $ringGroup->fallback_action;

        if ($fallbackAction === RingGroupFallbackAction::EXTENSION) {
            $fallbackExtensionId = $ringGroup->fallback_extension_id;

            if ($fallbackExtensionId) {
                $fallbackExtension = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                    ->where('id', $fallbackExtensionId)
                    ->where('organization_id', $ringGroup->organization_id)
                    ->first();

                if ($fallbackExtension && $fallbackExtension->isActive()) {
                    // Route to the fallback extension
                    return response(
                        CxmlBuilder::dialExtension($fallbackExtension->extension_number, $ringGroup->timeout ?? 30),
                        200,
                        ['Content-Type' => 'text/xml']
                    );
                }
            }

            // If fallback extension not found or invalid, fall back to hangup
        }

        // Default fallback: hangup with message
        return response(
            CxmlBuilder::unavailable('No agents available. Goodbye.'),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
