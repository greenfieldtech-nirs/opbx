<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Enums\RingGroupStrategy as StrategyEnum;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
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

        // Get member at current attempt index
        // Attempts are 1-based (from SessionData) or 0 (initial)
        // If initial, we want member index 0. If attempt 1 (meaning first tried), we want index 1.

        $index = $attempt === 0 ? 0 : $attempt;

        // If we exhausted all members (index >= count), look at ring_turns (loops)
        // For simple sequential, we usually iterate once.
        if ($index >= $members->count()) {
            return $this->handleFallback($ringGroup);
        }

        // Get the specific member
        $member = $members->values()->get($index);

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

        $nextAttempt = $index + 1;

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
        // TODO: Implement proper fallback actions (Voicemail, Redirect, Hangup)
        return response(
            CxmlBuilder::unavailable('No agents available.'),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
