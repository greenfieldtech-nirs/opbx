<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\ExtensionType;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\IvrStateService;

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
        private readonly VoiceRoutingCacheService $cache,
        private readonly IvrStateService $ivrStateService,
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

        Log::info('VoiceRoutingManager: Starting routing logic', [
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId
        ]);

        // 0. Check Business Hours
        $businessHoursResponse = $this->checkBusinessHours($orgId, $request->input('CallSid', ''));
        if ($businessHoursResponse) {
            return $businessHoursResponse;
        }

        // 1. Resolve Target (DID or Extension)
        // First check if it's a DID (scoped to authenticated organization)
        Log::info('VoiceRoutingManager: Checking for DID', [
            'to' => $to,
            'org_id' => $orgId,
        ]);

        $did = DidNumber::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('phone_number', $to)
            ->where('organization_id', $orgId)
            ->first();

        Log::info('VoiceRoutingManager: DID lookup result', [
            'did_found' => $did !== null,
            'did_id' => $did?->id,
            'did_phone_number' => $did?->phone_number,
            'did_routing_type' => $did?->routing_type,
        ]);

        // If not a DID, it might be an internal extension
        // Using cache service to look up extension by number
        // Normalize number logic might be needed here, or assumed normalized by middleware/controller

        Log::info('VoiceRoutingManager: Checking for extension', [
            'to' => $to,
            'org_id' => $orgId,
        ]);

        $extension = $this->cache->getExtension($orgId, $to);

        Log::info('VoiceRoutingManager: Extension lookup result', [
            'extension_found' => $extension !== null,
            'extension_id' => $extension?->id,
            'extension_number' => $extension?->extension_number,
            'extension_type' => $extension?->type?->value,
            'extension_status' => $extension?->status,
            'extension_active' => $extension?->isActive(),
        ]);
        if ($extension && $extension->isActive()) {
            // Internal call routing to an Extension
            Log::info('VoiceRoutingManager: Internal extension destination', [
                'extension' => $extension->extension_number,
                'type' => $extension->type->value
            ]);

            // Create destination array based on extension type
            $destination = $this->resolveExtensionDestination($extension, $orgId);

            Log::info('VoiceRoutingManager: Extension destination resolution', [
                'extension' => $extension->extension_number,
                'type' => $extension->type->value,
                'destination' => $destination,
                'destination_empty' => empty($destination),
            ]);

            if (empty($destination)) {
                Log::warning('VoiceRoutingManager: Could not resolve destination for extension', [
                    'extension' => $extension->extension_number,
                    'type' => $extension->type->value,
                    'org_id' => $orgId
                ]);
                return response(CxmlBuilder::unavailable('Extension configuration error'), 200, ['Content-Type' => 'text/xml']);
            }

            return $this->executeStrategy($extension->type, $request, new DidNumber(), $destination);
        }

        Log::info('VoiceRoutingManager: No destination found, returning unavailable', [
            'to' => $to,
            'org_id' => $orgId,
        ]);

        return response(CxmlBuilder::unavailable('Destination not found'), 200, ['Content-Type' => 'text/xml']);
    }

    private function routeDidCall(Request $request, DidNumber $did): Response
    {
        // 2. Resolve Final Destination based on DID routing_type
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
        $type = $did->routing_type; // 'extension', 'ring_group', 'conference_room', 'ivr_menu' ... matches checks?

        if ($did->routing_type === 'extension') {
            $extensionId = $config['extension_id'] ?? null;
            if ($extensionId) {
                $extension = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                    ->where('organization_id', $did->organization_id)
                    ->find($extensionId);
                if ($extension) {
                    return ['extension' => $extension];
                }
            }
        }

        if ($did->routing_type === 'ring_group') {
            $rgId = $config['ring_group_id'] ?? null;
            if ($rgId) {
                $rg = RingGroup::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                    ->where('organization_id', $did->organization_id)
                    ->find($rgId);
                if ($rg) {
                    return ['ring_group' => $rg];
                }
            }
        }

        if ($type === 'conference_room') {
            $roomId = $config['conference_room_id'] ?? null;
            if ($roomId) {
                $room = ConferenceRoom::find($roomId);
                return $room ? ['conference_room' => $room] : [];
            }
        }

        if ($type === 'ivr_menu') {
            $ivrMenuId = $config['ivr_menu_id'] ?? null;
            if ($ivrMenuId) {
                $ivrMenu = IvrMenu::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                    ->where('organization_id', $did->organization_id)
                    ->find($ivrMenuId);
                if ($ivrMenu) {
                    return ['ivr_menu' => $ivrMenu];
                }
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
        if (isset($destination['ivr_menu'])) {
            return ExtensionType::IVR;
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
        $organizationId = (int) $request->input('_organization_id');

        // Extract attempt number from query params or session_data JSON
        $attempt = (int) $request->input('attempt_number', 0);
        if ($attempt === 0) {
            // Try to extract from session_data JSON if not in query params
            $sessionDataJson = $request->input('session_data');
            if ($sessionDataJson) {
                $sessionData = json_decode($sessionDataJson, true);
                $attempt = (int) ($sessionData['attempt_number'] ?? 0);
            }
        }

        if (!$rgId) {
            return response(CxmlBuilder::unavailable('Ring group context missing'), 200, ['Content-Type' => 'text/xml']);
        }

        $rg = RingGroup::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $rgId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$rg) {
            Log::warning('VoiceRoutingManager: Ring group not found in callback', [
                'ring_group_id' => $rgId,
                'organization_id' => $organizationId
            ]);
            return response(CxmlBuilder::unavailable('Ring group not found'), 200, ['Content-Type' => 'text/xml']);
        }

        $destination = ['ring_group' => $rg];

        // Execute Ring Group Strategy
        return $this->executeStrategy(ExtensionType::RING_GROUP, $request, new DidNumber(), $destination);
    }

    private function resolveExtensionDestination(Extension $extension, int $organizationId): array
    {
        $extensionType = $extension->type;

        Log::info('VoiceRoutingManager: Resolving extension destination', [
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
            'extension_type_raw' => $extensionType,
            'organization_id' => $organizationId,
        ]);

        // Handle both enum and string types for backward compatibility
        if ($extensionType instanceof ExtensionType) {
            $typeValue = $extensionType->value;
        } else {
            $typeValue = (string) $extensionType;
        }

        Log::info('VoiceRoutingManager: Extension type resolved', [
            'type_value' => $typeValue,
        ]);

        // Use if statements instead of match for better debugging and reliability
        if ($typeValue === 'user') {
            Log::debug('VoiceRoutingManager: User extension destination', ['extension' => $extension->extension_number]);
            return ['extension' => $extension];
        } elseif ($typeValue === 'conference') {
            return $this->resolveConferenceDestination($extension, $organizationId);
        } elseif ($typeValue === 'ring_group') {
            return $this->resolveRingGroupDestination($extension, $organizationId);
        } elseif ($typeValue === 'ivr') {
            return $this->resolveIvrDestination($extension, $organizationId);
        } elseif ($typeValue === 'ai_assistant') {
            return $this->resolveAiAssistantDestination($extension, $organizationId);
        } elseif ($typeValue === 'custom_logic') {
            return ['extension' => $extension]; // Custom logic not yet implemented
        } elseif ($typeValue === 'forward') {
            return ['extension' => $extension];
        } elseif ($typeValue === 'queue') {
            return ['extension' => $extension]; // Queue routing not yet implemented
        } else {
            Log::warning('VoiceRoutingManager: Unknown extension type, falling back to extension', [
                'extension_number' => $extension->extension_number,
                'type_raw' => $extensionType,
                'type_value' => $typeValue
            ]);
            return ['extension' => $extension]; // Fallback for unknown types
        }
    }

    private function resolveConferenceDestination(Extension $extension, int $organizationId): array
    {
        $conferenceRoomId = $extension->configuration['conference_room_id'] ?? null;
        if (!$conferenceRoomId) {
            Log::warning('VoiceRoutingManager: Conference extension missing conference_room_id', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'organization_id' => $organizationId
            ]);
            return [];
        }

        $room = ConferenceRoom::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $conferenceRoomId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$room) {
            Log::warning('VoiceRoutingManager: Conference room not found', [
                'extension_id' => $extension->id,
                'conference_room_id' => $conferenceRoomId,
                'organization_id' => $organizationId
            ]);
            return [];
        }

        return ['conference_room' => $room];
    }

    private function resolveAiAssistantDestination(Extension $extension, int $organizationId): array
    {
        // AI Assistant routing is handled by the AiAgentRoutingStrategy
        // Configuration is extracted in the strategy itself
        return ['extension' => $extension];
    }

    private function resolveRingGroupDestination(Extension $extension, int $organizationId): array
    {
        $ringGroupId = $extension->configuration['ring_group_id'] ?? null;
        if (!$ringGroupId) {
            Log::warning('VoiceRoutingManager: Ring group extension missing ring_group_id', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'organization_id' => $organizationId
            ]);
            return [];
        }

        $ringGroup = RingGroup::where('id', $ringGroupId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$ringGroup) {
            Log::warning('VoiceRoutingManager: Ring group not found', [
                'extension_id' => $extension->id,
                'ring_group_id' => $ringGroupId,
                'organization_id' => $organizationId
            ]);
            return [];
        }

        return ['ring_group' => $ringGroup];
    }

    private function resolveIvrDestination(Extension $extension, int $organizationId): array
    {
        $ivrMenuId = $extension->configuration['ivr_id'] ?? null;
        if (!$ivrMenuId) {
            Log::warning('VoiceRoutingManager: IVR extension missing ivr_id', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'organization_id' => $organizationId
            ]);
            return [];
        }

        $ivrMenu = IvrMenu::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('id', $ivrMenuId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$ivrMenu) {
            Log::warning('VoiceRoutingManager: IVR menu not found', [
                'extension_id' => $extension->id,
                'ivr_menu_id' => $ivrMenuId,
                'organization_id' => $organizationId
            ]);
            return [];
        }

        return ['ivr_menu' => $ivrMenu];
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
        }

        return null;
    }

    public function handleIvrInput(Request $request): Response
    {
        try {
            $callSid = $request->input('CallSid');
            $digits = $request->input('Digits', '');
            $menuId = (int) $request->query('menu_id');
            $orgId = (int) $request->input('_organization_id');

            Log::info('IVR Input: Processing DTMF input', [
                'call_sid' => $callSid,
                'digits' => $digits,
                'menu_id' => $menuId,
                'org_id' => $orgId,
                'sequence_number' => $request->input('SequenceNumber', 'unknown'),
            ]);

        // Note: Idempotency checking removed for IVR input to allow users to correct their input
        // Users may press wrong digits and need to make subsequent attempts

            // Validate menu exists and belongs to organization
            $ivrMenu = IvrMenu::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                ->where('id', $menuId)
                ->where('organization_id', $orgId)
                ->where('status', 'active')
                ->first();

            if (!$ivrMenu) {
                Log::warning('IVR Input: Menu not found or inactive', [
                    'call_sid' => $callSid,
                    'menu_id' => $menuId,
                    'org_id' => $orgId,
                ]);
                return response(
                    CxmlBuilder::sayWithHangup('Menu configuration error.', true),
                    200,
                    ['Content-Type' => 'text/xml']
                );
            }

            Log::info('IVR Input: Menu found and active', [
                'call_sid' => $callSid,
                'menu_id' => $menuId,
                'menu_name' => $ivrMenu->name,
                'options_count' => $ivrMenu->options->count(),
                'options' => $ivrMenu->options->map(function ($option) {
                    return [
                        'id' => $option->id,
                        'input_digits' => $option->input_digits,
                        'description' => $option->description,
                        'destination_type' => $option->destination_type->value,
                        'destination_id' => $option->destination_id,
                    ];
                })->toArray(),
            ]);

            // Get current call state
            $callState = $this->ivrStateService->getCallState($callSid);

            if (!$callState) {
                Log::warning('IVR Input: No call state found', [
                    'call_sid' => $callSid,
                    'menu_id' => $menuId,
                ]);
                return response(
                    CxmlBuilder::sayWithHangup('Call state error. Please try again.', true),
                    200,
                    ['Content-Type' => 'text/xml']
                );
            }

            // Handle no input (timeout)
            if (empty($digits)) {
                return $this->handleNoInput($request, $ivrMenu, $callState);
            }

            // Find matching option
            $option = $ivrMenu->findOptionByDigits($digits);

            Log::info('IVR Input: Option lookup result', [
                'call_sid' => $callSid,
                'digits' => $digits,
                'option_found' => $option !== null,
                'option_id' => $option?->id,
                'option_destination_type' => $option?->destination_type->value,
                'option_destination_id' => $option?->destination_id,
            ]);

            if ($option) {
                // Valid option selected
                return $this->handleValidOption($request, $ivrMenu, $option);
            } else {
                // Invalid option
                return $this->handleInvalidOption($request, $ivrMenu, $callState, $digits);
            }
        } catch (\Exception $e) {
            Log::error('IVR Input: Unexpected exception in handleIvrInput', [
                'call_sid' => $request->input('CallSid'),
                'digits' => $request->input('Digits', ''),
                'menu_id' => $request->query('menu_id'),
                'org_id' => $request->input('_organization_id'),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response(
                CxmlBuilder::sayWithHangup('An unexpected error occurred.', true),
                200,
                ['Content-Type' => 'text/xml']
            );
        }
    }

    /**
     * Handle case where caller provides no input (timeout).
     */
    private function handleNoInput(Request $request, IvrMenu $ivrMenu, array $callState): Response
    {
        $callSid = $request->input('CallSid');
        $turnCount = $this->ivrStateService->incrementTurnCount($callSid);

        Log::info('IVR Input: No input provided (timeout)', [
            'call_sid' => $callSid,
            'menu_id' => $ivrMenu->id,
            'turn_count' => $turnCount,
            'max_turns' => $ivrMenu->max_turns,
        ]);

        if ($this->ivrStateService->isMaxTurnsExceeded($callSid, $ivrMenu->max_turns)) {
            // Max turns exceeded, route to failover
            return $this->routeToFailoverDestination($request, $ivrMenu);
        }

        // Replay menu
        $destination = ['ivr_menu' => $ivrMenu];
        return $this->executeStrategy(\App\Enums\ExtensionType::IVR, $request, new DidNumber(), $destination);
    }

    /**
     * Handle valid option selection.
     */
    private function handleValidOption(Request $request, IvrMenu $ivrMenu, $option): Response
    {
        $callSid = $request->input('CallSid');
        $digits = $request->input('Digits');

        Log::info('IVR Input: Valid option selected', [
            'call_sid' => $callSid,
            'menu_id' => $ivrMenu->id,
            'digits' => $digits,
            'destination_type' => $option->destination_type->value,
            'destination_id' => $option->destination_id,
        ]);

            // Route to the selected destination
            return $this->routeToOptionDestination($request, $option, $ivrMenu);
    }

    /**
     * Handle invalid option selection.
     */
    private function handleInvalidOption(Request $request, IvrMenu $ivrMenu, array $callState, string $digits): Response
    {
        $callSid = $request->input('CallSid');
        $turnCount = $this->ivrStateService->incrementTurnCount($callSid);

        Log::info('IVR Input: Invalid option selected', [
            'call_sid' => $callSid,
            'menu_id' => $ivrMenu->id,
            'digits' => $digits,
            'turn_count' => $turnCount,
            'max_turns' => $ivrMenu->max_turns,
        ]);

        if ($this->ivrStateService->isMaxTurnsExceeded($callSid, $ivrMenu->max_turns)) {
            // Max turns exceeded, route to failover
            return $this->routeToFailoverDestination($request, $ivrMenu);
        }

        // Play error message and replay menu
        $errorMessage = 'Invalid menu option, please try again.';
        $destination = ['ivr_menu' => $ivrMenu];
        $ivrStrategy = new \App\Services\VoiceRouting\Strategies\IvrRoutingStrategy($this->ivrStateService);

        // Pass error message to IVR strategy - it will be included in the Gather operation
        return $ivrStrategy->route($request, new DidNumber(), $destination, $errorMessage);
    }

    /**
     * Route call to option destination.
     */
    private function routeToOptionDestination(Request $request, $option, IvrMenu $ivrMenu): Response
    {
        Log::info('DEBUG: routeToOptionDestination called', [
            'ivr_menu_type' => gettype($ivrMenu),
            'ivr_menu_class' => get_class($ivrMenu),
            'ivr_menu_id' => $ivrMenu->id,
            'ivr_menu_org_id' => $ivrMenu->organization_id,
        ]);

        try {
            $destination = [];
            Log::info('IVR Input: Attempting to get validated destination', [
                'call_sid' => $request->input('CallSid'),
                'option_id' => $option->id ?? 'mock',
                'destination_type' => $option->destination_type->value ?? $option->destination_type,
                'destination_id' => $option->destination_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_org_id' => $ivrMenu->organization_id,
            ]);

            $validatedDestination = $option->getValidatedDestination($ivrMenu);

            Log::debug('IVR Input: getValidatedDestination result', [
                'validated_destination' => $validatedDestination,
                'is_null' => $validatedDestination === null,
            ]);

            if (!$validatedDestination) {
                Log::error('IVR Input: Destination validation failed', [
                    'call_sid' => $request->input('CallSid'),
                    'option_id' => $option->id ?? 'mock',
                    'destination_type' => is_object($option->destination_type) ? $option->destination_type->value : $option->destination_type,
                    'destination_id' => $option->destination_id,
                ]);

                return response(
                    CxmlBuilder::sayWithHangup('Destination is no longer available.', true),
                    200,
                    ['Content-Type' => 'text/xml']
                );
            }

            Log::info('IVR Input: Validated destination found', [
                'call_sid' => $request->input('CallSid'),
                'option_id' => $option->id ?? 'mock',
                'destination_type' => is_object($option->destination_type) ? $option->destination_type->value : $option->destination_type,
                'destination_id' => $option->destination_id,
                'validated_destination_id' => $validatedDestination->id,
                'validated_destination_type' => get_class($validatedDestination),
            ]);

            switch ($option->destination_type) {
                case \App\Enums\IvrDestinationType::EXTENSION:
                    Log::info('IVR Input: Routing to extension', [
                        'call_sid' => $request->input('CallSid'),
                        'option_id' => $option->id ?? 'mock',
                        'destination_id' => $option->destination_id,
                        'extension_id' => $validatedDestination->id,
                        'extension_number' => $validatedDestination->extension_number,
                        'extension_type' => $validatedDestination->type->value,
                    ]);
                    $destination = ['extension' => $validatedDestination];

                    Log::info('IVR Input: About to execute strategy', [
                        'extension_type' => $validatedDestination->type,
                        'extension_type_value' => $validatedDestination->type->value,
                    ]);

                    return $this->executeStrategy($validatedDestination->type, $request, new DidNumber(), $destination);

                case \App\Enums\IvrDestinationType::RING_GROUP:
                    Log::info('IVR Input: Routing to ring group', [
                        'call_sid' => $request->input('CallSid'),
                        'option_id' => $option->id,
                        'ring_group_id' => $validatedDestination->id,
                        'ring_group_name' => $validatedDestination->name,
                    ]);
                    $destination = ['ring_group' => $validatedDestination];
                    return $this->executeStrategy(\App\Enums\ExtensionType::RING_GROUP, $request, new DidNumber(), $destination);

                case \App\Enums\IvrDestinationType::CONFERENCE_ROOM:
                    Log::info('IVR Input: Routing to conference room', [
                        'call_sid' => $request->input('CallSid'),
                        'option_id' => $option->id,
                        'conference_room_id' => $validatedDestination->id,
                        'conference_room_name' => $validatedDestination->name,
                    ]);
                    $destination = ['conference_room' => $validatedDestination];
                    return $this->executeStrategy(\App\Enums\ExtensionType::CONFERENCE, $request, new DidNumber(), $destination);

                case \App\Enums\IvrDestinationType::IVR_MENU:
                    Log::info('IVR Input: Routing to IVR menu', [
                        'call_sid' => $request->input('CallSid'),
                        'option_id' => $option->id,
                        'ivr_menu_id' => $validatedDestination->id,
                        'ivr_menu_name' => $validatedDestination->name,
                    ]);
                    $destination = ['ivr_menu' => $validatedDestination];
                    return $this->executeStrategy(\App\Enums\ExtensionType::IVR, $request, new DidNumber(), $destination);
            }

            // Fallback for invalid destination
            Log::error('IVR Input: Destination model not found', [
                'call_sid' => $request->input('CallSid'),
                'option_id' => $option->id,
                'destination_type' => $option->destination_type->value,
                'destination_id' => $option->destination_id,
            ]);

            return response(
                CxmlBuilder::sayWithHangup('Destination configuration error.', true),
                200,
                ['Content-Type' => 'text/xml']
            );
        } catch (\Exception $e) {
            Log::error('IVR Input: Exception in destination routing', [
                'call_sid' => $request->input('CallSid'),
                'option_id' => $option->id,
                'destination_type' => $option->destination_type->value,
                'destination_id' => $option->destination_id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response(
                CxmlBuilder::sayWithHangup('Routing error occurred.', true),
                200,
                ['Content-Type' => 'text/xml']
            );
        }
    }

    /**
     * Route call to IVR menu failover destination.
     */
    private function routeToFailoverDestination(Request $request, IvrMenu $ivrMenu): Response
    {
        $callSid = $request->input('CallSid');

        Log::info('IVR Input: Routing to failover destination', [
            'call_sid' => $callSid,
            'menu_id' => $ivrMenu->id,
            'failover_type' => $ivrMenu->failover_destination_type->value,
            'failover_id' => $ivrMenu->failover_destination_id,
        ]);

        if ($ivrMenu->failover_destination_type === \App\Enums\IvrDestinationType::HANGUP) {
            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'text/xml']);
        }

        // Route to failover destination directly
        return $this->routeToDestination($request, $ivrMenu->failover_destination_type, $ivrMenu->failover_destination_id, $ivrMenu);
    }

    private function routeToDestination(Request $request, $destinationType, $destinationId, IvrMenu $ivrMenu): Response
    {
        Log::info('IVR Input: Routing to destination', [
            'call_sid' => $request->input('CallSid'),
            'destination_type' => is_object($destinationType) ? $destinationType->value : $destinationType,
            'destination_id' => $destinationId,
            'ivr_menu_id' => $ivrMenu->id,
        ]);

        // Create a temporary destination object for validation
        $tempDestination = (object) [
            'destination_type' => $destinationType,
            'destination_id' => $destinationId,
        ];

        // Use the same validation logic as getValidatedDestination
        $validatedDestination = null;

        if ($destinationType === \App\Enums\IvrDestinationType::EXTENSION) {
            $validatedDestination = Extension::withoutGlobalScope(OrganizationScope::class)
                ->where('extension_number', (string) $destinationId)
                ->where('organization_id', $ivrMenu->organization_id)
                ->first();
        } elseif ($destinationType === \App\Enums\IvrDestinationType::RING_GROUP) {
            $validatedDestination = RingGroup::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $destinationId)
                ->where('organization_id', $ivrMenu->organization_id)
                ->first();
        } elseif ($destinationType === \App\Enums\IvrDestinationType::CONFERENCE_ROOM) {
            $validatedDestination = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $destinationId)
                ->where('organization_id', $ivrMenu->organization_id)
                ->first();
        } elseif ($destinationType === \App\Enums\IvrDestinationType::IVR_MENU) {
            $validatedDestination = IvrMenu::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $destinationId)
                ->where('organization_id', $ivrMenu->organization_id)
                ->first();
        }

        if (!$validatedDestination) {
            Log::error('IVR Input: Destination validation failed for failover', [
                'call_sid' => $request->input('CallSid'),
                'destination_type' => is_object($destinationType) ? $destinationType->value : $destinationType,
                'destination_id' => $destinationId,
            ]);

            return response(
                CxmlBuilder::sayWithHangup('Destination is no longer available.', true),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        // Route based on destination type
        if ($destinationType === \App\Enums\IvrDestinationType::EXTENSION) {
            $destination = ['extension' => $validatedDestination];
            return $this->executeStrategy($validatedDestination->type, $request, new DidNumber(), $destination);
        } elseif ($destinationType === \App\Enums\IvrDestinationType::RING_GROUP) {
            $destination = ['ring_group' => $validatedDestination];
            return $this->executeStrategy(\App\Enums\ExtensionType::RING_GROUP, $request, new DidNumber(), $destination);
        } elseif ($destinationType === \App\Enums\IvrDestinationType::CONFERENCE_ROOM) {
            $destination = ['conference_room' => $validatedDestination];
            return $this->executeStrategy(\App\Enums\ExtensionType::CONFERENCE, $request, new DidNumber(), $destination);
        } elseif ($destinationType === \App\Enums\IvrDestinationType::IVR_MENU) {
            $destination = ['ivr_menu' => $validatedDestination];
            return $this->executeStrategy(\App\Enums\ExtensionType::IVR, $request, new DidNumber(), $destination);
        }

        return response(
            CxmlBuilder::sayWithHangup('Unknown destination type.', true),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
