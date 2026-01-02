<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\ExtensionType;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Models\RingGroup;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\Security\RoutingSentryService;
use App\Services\VoiceRouting\Strategies\RoutingStrategy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VoiceRoutingManager
{
    /** @var Collection<int, RoutingStrategy> */
    private Collection $strategies;

    public function __construct(
        private readonly RoutingSentryService $sentry,
        private readonly VoiceRoutingCacheService $cache,
        iterable $strategies = []
    ) {
        $this->strategies = collect($strategies);
    }

    /**
     * Handle inbound call routing.
     */
    public function handleInbound(Request $request): Response
    {
        $to = $request->input('To');
        $from = $request->input('From');
        $orgId = (int) $request->input('_organization_id');

        Log::info('VoiceRoutingManager: Handling inbound call', [
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId
        ]);

        // 0. Check Business Hours
        $businessHoursResponse = $this->checkBusinessHours($orgId, $request->input('CallSid', ''));
        if ($businessHoursResponse) {
            return $businessHoursResponse;
        }

        // 1. Resolve Destination (DID or Internal Extension)
        // We first check if the 'To' number matches a DID
        $did = DidNumber::where('phone_number', $to)
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->first();

        // If not a DID, it might be an internal extension dialing another extension (or internal transfer)
        // But handleInbound acts on the 'To' number.
        // If the call arrives at this controller, it's either from PSTN (to a DID) 
        // OR checks for Internal calls were done in classification.
        // The Controller's classifyCall logic distinguished 'internal' vs 'external'.

        // If we are replacing the controller logic, we need to handle both.
        // However, `handleInbound` suggests we are routing *to* something.

        if ($did) {
            return $this->routeDidCall($request, $did);
        }

        // Check if it's an internal extension
        // Using cache service to look up extension by number
        // Normalize number logic might be needed here, or assumed normalized by middleware/controller
        // Cloudonix usually sends E.164. Extension numbers might be 4 digits.

        $extension = $this->cache->getExtension($orgId, $to);
        if ($extension && $extension->isActive()) {
            // Internal call routing to an Extension
            // We need a dummy DID model or handle internal calls slightly differently in interface?
            // The RoutingStrategy interface requires DidNumber.
            // This suggests my Interface design assumed DID-based routing primarily.
            // If internal, `DidNumber` might be null or we need to adapt.
            // Let's create a temporary/mock DidNumber context or make DidNumber nullable in interface.
            // But existing implementations might depend on it.
            // 'UserRoutingStrategy' didn't use $did heavily, others might not either.

            // Refactoring Interface is risky now.
            // I'll create a transient DidNumber instance for internal calls if strictly needed,
            // or pass null if I update specific strategies to handle it.
            // But simpler: The implementation plan Phase 5.1 says "Resolve Destination".

            // Let's assume for now we only route DID calls via this manager method, 
            // OR we create a synthetic DID wrapper.

            Log::info('VoiceRoutingManager: Internal extension destination', ['extension' => $extension->extension_number]);

            // We need to match strategy for the Extension itself.
            // UserRoutingStrategy handles ExtensionType::USER

            // Create a synthetic destination array
            $destination = ['extension' => $extension];

            return $this->executeStrategy($extension->type, $request, new DidNumber(), $destination);
        }

        return response(CxmlBuilder::unavailable('Destination not found'), 200, ['Content-Type' => 'text/xml']);
    }

    private function routeDidCall(Request $request, DidNumber $did): Response
    {
        // 2. Security Check (Sentry)
        $sentryResult = $this->sentry->checkInbound($request, $did);
        if (!$sentryResult['allowed']) {
            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'text/xml']);
        }

        // 3. Resolve Final Destination based on DID routing_type
        $destination = $this->resolveDestination($did);

        if (empty($destination)) {
            Log::warning('VoiceRoutingManager: No destination found for DID', ['did' => $did->phone_number]);
            return response(CxmlBuilder::unavailable('Configuration error'), 200, ['Content-Type' => 'text/xml']);
        }

        // 4. Determine Extension Type for Strategy Selection
        $type = $this->determineExtensionType($did, $destination);

        if (!$type) {
            return response(CxmlBuilder::unavailable('Unknown routing type'), 200, ['Content-Type' => 'text/xml']);
        }

        // 5. Execute Strategy
        return $this->executeStrategy($type, $request, $did, $destination);
    }

    private function resolveDestination(DidNumber $did): array
    {
        $config = $did->routing_config ?? [];
        $type = $did->routing_type; // 'extension', 'ring_group', 'conference_room' ... matches checks?

        if ($type === 'extension') {
            $extId = $config['extension_id'] ?? null;
            if ($extId) {
                // We should use the repository/cache to get the extension
                $extension = \App\Models\Extension::find($extId); // Should use cache/repo
                return $extension ? ['extension' => $extension] : [];
            }
        }

        if ($type === 'ring_group') {
            $rgId = $config['ring_group_id'] ?? null;
            if ($rgId) {
                $rg = RingGroup::find($rgId);
                return $rg ? ['ring_group' => $rg] : [];
            }
        }

        if ($type === 'conference_room') {
            $roomId = $config['conference_room_id'] ?? null;
            if ($roomId) {
                $room = ConferenceRoom::find($roomId);
                return $room ? ['conference_room' => $room] : [];
            }
        }

        return [];
    }

    private function determineExtensionType(DidNumber $did, array $destination): ?ExtensionType
    {
        if (isset($destination['extension'])) {
            return $destination['extension']->type;
        }
        if (isset($destination['ring_group'])) {
            return ExtensionType::RING_GROUP;
        }
        if (isset($destination['conference_room'])) {
            return ExtensionType::CONFERENCE;
        }

        return null;
    }

    private function executeStrategy(ExtensionType $type, Request $request, DidNumber $did, array $destination): Response
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->canHandle($type)) {
                return $strategy->route($request, $did, $destination);
            }
        }

        Log::error('VoiceRoutingManager: No strategy found for type', ['type' => $type->value]);
        return response(CxmlBuilder::unavailable('Routing strategy not found'), 200, ['Content-Type' => 'text/xml']);
    }

    public function routeRingGroupCallback(Request $request): Response
    {
        $rgId = $request->input('ring_group_id') ?? $request->input('SessionData.ring_group_id');

        if (!$rgId) {
            return response(CxmlBuilder::unavailable('Ring group context missing'), 200, ['Content-Type' => 'text/xml']);
        }

        $rg = RingGroup::find($rgId);
        if (!$rg) {
            return response(CxmlBuilder::unavailable('Ring group not found'), 200, ['Content-Type' => 'text/xml']);
        }

        $destination = ['ring_group' => $rg];

        // Execute Ring Group Strategy
        return $this->executeStrategy(ExtensionType::RING_GROUP, $request, new DidNumber(), $destination);
    }

    private function checkBusinessHours(int $organizationId, string $callSid): ?Response
    {
        $schedule = $this->cache->getActiveBusinessHoursSchedule($organizationId);

        if (!$schedule) {
            return null;
        }

        if (!$schedule->isCurrentlyOpen()) {
            Log::info('VoiceRoutingManager: Business is closed', ['call_sid' => $callSid]);
            return response(
                CxmlBuilder::unavailable('Thank you for calling. We are currently closed. Please call back during our business hours.'),
                200,
                ['Content-Type' => 'text/xml']
            );
    public function handleIvrInput(Request $request): Response
    {
        // Placeholder for IVR input handling
        $message = 'Hello. This is the Open PBX voice routing system. Phase zero placeholder response. Call type: unknown.';
        return response(CxmlBuilder::sayWithHangup($message, true), 200, ['Content-Type' => 'text/xml']);
    }
}

        return null;
    }
}
