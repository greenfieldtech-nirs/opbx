<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\ExtensionType;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\OutboundWhitelist;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\IvrStateService;
use App\Services\PhoneNumberService;
use App\Services\VoiceRouting\Strategies\RoutingStrategy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Voice Routing Manager
 *
 * Central service for handling inbound voice call routing in the PBX system.
 * Supports multiple routing types including DIDs, extensions, ring groups,
 * conference rooms, IVR menus, AI assistants, and outbound routing.
 *
 * This service coordinates between various routing strategies and provides
 * unified call routing logic with proper error handling and logging.
 */
class VoiceRoutingManager
{
    /** @var Collection<int, RoutingStrategy> */
    private Collection $strategies;

    /**
     * Create a new VoiceRoutingManager instance.
     *
     * @param  VoiceRoutingCacheService  $cache  Service for caching extension and routing data
     * @param  IvrStateService  $ivrStateService  Service for managing IVR call state
     * @param  PhoneNumberService  $phoneNumberService  Service for phone number parsing and validation
     * @param  iterable<RoutingStrategy>  $strategies  Collection of routing strategies
     */
    public function __construct(
        private readonly VoiceRoutingCacheService $cache,
        private readonly IvrStateService $ivrStateService,
        private readonly PhoneNumberService $phoneNumberService,
        iterable $strategies = []
    ) {
        $this->strategies = collect($strategies);
    }

    /**
     * Handle inbound call routing.
     *
     * Main entry point for processing incoming calls. Determines the routing path
     * based on the call direction and destination number.
     *
     * @param  Request  $request  The incoming HTTP request containing call details
     * @return Response CXML response with routing instructions
     *
     * @throws \Exception If routing cannot be determined
     */
    public function handleInbound(Request $request): Response
    {
        $direction = $request->input('Direction', 'unknown');
        $to = $request->input('To');
        $from = $request->input('From');
        $orgId = (int) $request->input('_organization_id');

        Log::info('VoiceRoutingManager: Handling call routing by direction', [
            'direction' => $direction,
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        return match ($direction) {
            'subscriber' => $this->handleSubscriberDirection($request),
            'inbound' => $this->handleInboundDirection($request),
            'outbound' => $this->handleOutgoingDirection($request),
            'application' => $this->handleApplicationDirection($request),
            default => $this->handleUnknownDirection($request, $direction),
        };
    }

    /**
     * Handle subscriber direction calls (internal PBX user calls).
     *
     * These are calls from internal PBX users to other internal destinations.
     * No business hours checking is applied to internal calls.
     *
     * @param  Request  $request  The incoming call request
     * @return Response CXML response with routing instructions
     */
    private function handleSubscriberDirection(Request $request): Response
    {
        $to = $request->input('To');
        $from = $request->input('From');
        $orgId = (int) $request->input('_organization_id');

        Log::info('VoiceRoutingManager: Handling subscriber direction call', [
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        // 1. Try extension routing first (direct extension dial)
        $extensionResponse = $this->handleExtensionRouting($request, $to, $orgId);
        if ($extensionResponse) {
            return $extensionResponse;
        }

        // 2. Try DID routing (fallback for internal calls)
        $didResponse = $this->handleDidRouting($request, $to, $orgId);
        if ($didResponse) {
            return $didResponse;
        }

        Log::info('VoiceRoutingManager: No destination found for subscriber call', [
            'to' => $to,
            'org_id' => $orgId,
        ]);

        return $this->createCxmlErrorResponse('Extension not found');
    }

    /**
     * Check if a phone number is assigned as a DID in the system.
     *
     * @param  string  $phoneNumber  The phone number to check
     * @param  int  $orgId  The organization ID
     * @return bool True if the phone number is assigned as an active DID
     */
    private function isAssignedPhoneNumber(string $phoneNumber, int $orgId): bool
    {
        $did = DidNumber::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('phone_number', $phoneNumber)
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->first();

        return $did !== null;
    }

    /**
     * Handle inbound direction calls (both internal and external).
     *
     * Determines call type based on whether the caller (From) is an assigned DID:
     * - If From is an assigned DID: internal call from a DID
     * - If From is not assigned: external inbound call to a DID (To)
     *
     * @param  Request  $request  The incoming call request
     * @return Response CXML response with routing instructions
     */
    private function handleInboundDirection(Request $request): Response
    {
        $to = $request->input('To');
        $from = $request->input('From');
        $orgId = (int) $request->input('_organization_id');

        Log::info('VoiceRoutingManager: Handling inbound direction call', [
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        // Validate required parameters
        if (empty($to) || empty($from)) {
            Log::warning('VoiceRoutingManager: Missing required call parameters', [
                'to' => $to,
                'from' => $from,
                'org_id' => $orgId,
            ]);

            return $this->createCxmlErrorResponse('Missing call parameters');
        }

        // Determine call type: check if From is an assigned phone number
        if ($this->isAssignedPhoneNumber($from, $orgId)) {
            Log::info('VoiceRoutingManager: Detected internal call from DID', [
                'from_did' => $from,
                'to' => $to,
                'org_id' => $orgId,
            ]);

            return $this->handleInternalCallFromDid($request);
        } else {
            Log::info('VoiceRoutingManager: Detected external inbound call to DID', [
                'from_external' => $from,
                'to_did' => $to,
                'org_id' => $orgId,
            ]);

            return $this->handleExternalInboundCall($request);
        }
    }

    /**
     * Handle internal calls originating from a DID.
     *
     * These are calls made from an assigned DID number (e.g., an extension calling out
     * through a DID). No business hours checking is applied to internal calls.
     *
     * @param  Request  $request  The incoming call request
     * @return Response CXML response with routing instructions
     */
    private function handleInternalCallFromDid(Request $request): Response
    {
        $to = $request->input('To');
        $from = $request->input('From');
        $orgId = (int) $request->input('_organization_id');

        Log::info('VoiceRoutingManager: Processing internal call from DID', [
            'from_did' => $from,
            'to' => $to,
            'org_id' => $orgId,
        ]);

        // 1. Try extension routing first (direct extension dial)
        $extensionResponse = $this->handleExtensionRouting($request, $to, $orgId);
        if ($extensionResponse) {
            return $extensionResponse;
        }

        // 2. Try DID routing (fallback for internal calls)
        $didResponse = $this->handleDidRouting($request, $to, $orgId);
        if ($didResponse) {
            return $didResponse;
        }

        Log::info('VoiceRoutingManager: No destination found for internal DID call', [
            'from_did' => $from,
            'to' => $to,
            'org_id' => $orgId,
        ]);

        return $this->createCxmlErrorResponse('Extension not found');
    }

    /**
     * Handle external inbound calls to a DID.
     *
     * These are calls from external numbers to an assigned DID. Business hours checking
     * is applied to determine routing behavior.
     *
     * @param  Request  $request  The incoming call request
     * @return Response CXML response with routing instructions
     */
    private function handleExternalInboundCall(Request $request): Response
    {
        $to = $request->input('To');
        $from = $request->input('From');
        $orgId = (int) $request->input('_organization_id');

        Log::info('VoiceRoutingManager: Processing external inbound call to DID', [
            'from_external' => $from,
            'to_did' => $to,
            'org_id' => $orgId,
        ]);

        // 0. Check Business Hours (only for external inbound calls)
        $businessHoursResponse = $this->checkBusinessHours($orgId, $request->input('CallSid', ''), $request);
        if ($businessHoursResponse) {
            return $businessHoursResponse;
        }

        // 1. Try DID routing
        $didResponse = $this->handleDidRouting($request, $to, $orgId);
        if ($didResponse) {
            return $didResponse;
        }

        // 2. Try extension routing (fallback)
        $extensionResponse = $this->handleExtensionRouting($request, $to, $orgId);
        if ($extensionResponse) {
            return $extensionResponse;
        }

        Log::info('VoiceRoutingManager: No destination found for external inbound call', [
            'from_external' => $from,
            'to_did' => $to,
            'org_id' => $orgId,
        ]);

        return $this->createCxmlErrorResponse('Destination not found');
    }

    /**
     * Handle outbound direction calls (internal to external calls).
     *
     * These are calls from internal extensions to external numbers.
     * Uses outbound whitelist routing.
     *
     * @param  Request  $request  The incoming call request
     * @return Response CXML response with routing instructions
     */
    private function handleOutgoingDirection(Request $request): Response
    {
        $to = $request->input('To');
        $from = $request->input('From');
        $orgId = (int) $request->input('_organization_id');

        Log::info('VoiceRoutingManager: Handling outbound direction call', [
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        // Try outbound routing via whitelist
        $outboundResponse = $this->handleOutboundRouting($request, $to, $from, $orgId);
        if ($outboundResponse) {
            return $outboundResponse;
        }

        Log::info('VoiceRoutingManager: Outbound call not allowed or no whitelist match', [
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        return $this->createCxmlErrorResponse('Outbound call not permitted');
    }

    /**
     * Handle application direction calls (API-initiated calls).
     *
     * These are calls initiated programmatically through the API.
     * Uses the same internal PBX user logic as subscriber calls.
     *
     * @param  Request  $request  The incoming call request
     * @return Response CXML response with routing instructions
     */
    private function handleApplicationDirection(Request $request): Response
    {
        $to = $request->input('To');
        $from = $request->input('From');
        $orgId = (int) $request->input('_organization_id');

        Log::info('VoiceRoutingManager: Handling application direction call', [
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        // Use the same logic as subscriber direction calls
        // 1. Try extension routing first (direct extension dial)
        $extensionResponse = $this->handleExtensionRouting($request, $to, $orgId);
        if ($extensionResponse) {
            return $extensionResponse;
        }

        // 2. Try DID routing (fallback for API-initiated calls)
        $didResponse = $this->handleDidRouting($request, $to, $orgId);
        if ($didResponse) {
            return $didResponse;
        }

        Log::info('VoiceRoutingManager: No destination found for application call', [
            'to' => $to,
            'org_id' => $orgId,
        ]);

        return $this->createCxmlErrorResponse('Extension not found');
    }

    /**
     * Handle calls with unknown direction.
     *
     * Falls back to default routing behavior for unrecognized directions.
     *
     * @param  Request  $request  The incoming call request
     * @param  string  $direction  The unrecognized direction value
     * @return Response CXML response with routing instructions
     */
    private function handleUnknownDirection(Request $request, string $direction): Response
    {
        $to = $request->input('To');
        $from = $request->input('From');
        $orgId = (int) $request->input('_organization_id');

        Log::warning('VoiceRoutingManager: Unknown call direction, falling back to default routing', [
            'direction' => $direction,
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        // Fall back to inbound behavior for unknown directions
        return $this->handleInboundDirection($request);
    }

    /**
     * Handle extension-based routing if an extension is found for the destination number.
     *
     * @param  Request  $request  The incoming call request
     * @param  string  $to  The destination extension number
     * @param  int  $orgId  The organization ID
     * @return Response|null CXML response if extension routing applies, null otherwise
     */
    private function handleExtensionRouting(Request $request, string $to, int $orgId): ?Response
    {
        Log::info('VoiceRoutingManager: Checking for extension', [
            'direction' => $request->input('Direction', 'unknown'),
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
            Log::info('VoiceRoutingManager: Internal extension destination', [
                'extension' => $extension->extension_number,
                'type' => $extension->type->value,
            ]);

            // Create destination array based on extension type
            $destination = $this->resolveExtensionDestination($extension, $orgId);

            Log::info('VoiceRoutingManager: Extension destination resolution', [
                'extension' => $extension->extension_number,
                'type' => $extension->type->value,
                'destination' => $destination,
            ]);

            // Route using extension strategies
            return $this->routeUsingExtensionStrategies($extension, $destination, $orgId, $request);
        }

        return null;
    }

    /**
     * Handle DID-based routing if a DID is found for the destination number.
     *
     * Checks if the destination number is configured as a DID in the system.
     * If found, routes the call according to the DID's routing configuration
     * (extension, ring group, conference, IVR, etc.).
     *
     * @param  Request  $request  The incoming call request
     * @param  string  $to  The destination phone number
     * @param  int  $orgId  The organization ID
     * @return Response|null CXML response if DID routing applies, null otherwise
     */
    private function handleDidRouting(Request $request, string $to, int $orgId): ?Response
    {
        Log::info('VoiceRoutingManager: Checking for DID', [
            'direction' => $request->input('Direction', 'unknown'),
            'to' => $to,
            'org_id' => $orgId,
        ]);

        $did = DidNumber::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('phone_number', $to)
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->first();

        Log::info('VoiceRoutingManager: DID lookup result', [
            'did_found' => $did !== null,
            'did_id' => $did?->id,
            'did_phone_number' => $did?->phone_number,
            'did_routing_type' => $did?->routing_type,
        ]);

        // If DID found, route according to DID configuration
        if ($did) {
            Log::info('VoiceRoutingManager: Routing to DID destination', [
                'did_id' => $did->id,
                'routing_type' => $did->routing_type,
            ]);

            return $this->routeDidCall($request, $did);
        }

        return null;
    }

    /**
     * Route a call based on DID configuration.
     *
     * @param  Request  $request  The incoming request
     * @param  DidNumber  $did  The DID number configuration
     * @return Response CXML response
     */
    private function routeDidCall(Request $request, DidNumber $did): Response
    {
        $destination = [];

        // Load the appropriate destination based on routing type
        switch ($did->routing_type) {
            case 'extension':
                $extension = $did->getExtensionAttribute();
                if ($extension) {
                    $destination['extension'] = $extension;
                }
                break;

            case 'ring_group':
                $ringGroup = $did->getRingGroupAttribute();
                if ($ringGroup) {
                    $destination['ring_group'] = $ringGroup;
                }
                break;

            case 'conference_room':
                $conferenceRoom = $did->getConferenceRoomAttribute();
                if ($conferenceRoom) {
                    $destination['conference_room'] = $conferenceRoom;
                }
                break;

            case 'ivr_menu':
            case 'ivr':
                $ivrMenu = $did->getIvrMenuAttribute();
                if ($ivrMenu) {
                    $destination['ivr_menu'] = $ivrMenu;
                }
                break;

            case 'business_hours':
                $schedule = $did->getBusinessHoursScheduleAttribute();
                if ($schedule) {
                    // For business hours, route based on current status
                    $currentAction = $schedule->getCurrentRouting();
                    if (! empty($currentAction)) {
                        return $this->routeToBusinessHoursAction(
                            $currentAction,
                            $did->organization_id,
                            $request->input('CallSid', ''),
                            $request
                        );
                    }
                }
                break;
        }

        if (empty($destination)) {
            Log::warning('VoiceRoutingManager: No valid destination found for DID routing', [
                'did_id' => $did->id,
                'routing_type' => $did->routing_type,
            ]);

            return $this->createCxmlErrorResponse('Destination not configured');
        }

        // Determine extension type from destination
        $extensionType = $this->determineExtensionType($did, $destination);

        if (! $extensionType) {
            return $this->createCxmlErrorResponse('Unsupported routing configuration');
        }

        return $this->executeStrategy($extensionType, $request, $did, $destination);
    }

    /**
     * Route to business hours action (extension number/ID).
     *
     * @param  string  $action  The extension number or ID to route to
     * @param  Request  $request  The incoming request
     */
    private function routeToBusinessHoursAction(string $action, int $organizationId, string $callSid, Request $request): Response
    {
        // If action is empty or null, allow normal routing to continue
        if (empty($action)) {
            return response('', 204); // No Content - continue with normal routing
        }

        Log::info('VoiceRoutingManager: Routing to business hours action', [
            'call_sid' => $callSid,
            'action' => $action,
            'organization_id' => $organizationId,
        ]);

        // Check if action is an extension number
        $extension = $this->cache->getExtension($organizationId, $action);

        if ($extension && $extension->isActive()) {
            Log::info('VoiceRoutingManager: Found extension for business hours action', [
                'call_sid' => $callSid,
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'extension_type' => $extension->type->value,
            ]);

            // Use the extension routing logic
            $destination = $this->resolveExtensionDestination($extension, $organizationId);

            return $this->routeUsingExtensionStrategies($extension, $destination, $organizationId, $request);
        }

        // If not an extension, treat as a direct extension number for dialing
        Log::info('VoiceRoutingManager: Treating action as direct extension number', [
            'call_sid' => $callSid,
            'action' => $action,
        ]);

        return response(
            CxmlBuilder::dialExtension($action, 30),
            200,
            ['Content-Type' => 'text/xml']
        );
    }

    /**
     * Find outbound whitelist entry that matches the destination number.
     *
     * Matches against both country codes and additional prefixes:
     * 1. Extract country calling code from destination number (e.g., "+1" from "+15551234567")
     * 2. Convert calling code to country code (e.g., "+1" -> "US")
     * 3. Find entries where destination_country matches either:
     *    - The ISO country code (e.g., "US", "IL", "GB")
     *    - The calling code directly (e.g., "+1", "+972", "+44")
     * 4. Within those matches, check destination_prefix for additional matching
     * 5. Return the longest matching prefix
     */
    private function findOutboundWhitelistEntry(int $organizationId, string $destinationNumber): ?OutboundWhitelist
    {
        // Get all outbound whitelist entries for the organization
        $whitelistEntries = OutboundWhitelist::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->get();

        Log::info('VoiceRoutingManager: Checking outbound whitelist entries', [
            'organization_id' => $organizationId,
            'destination_number' => $destinationNumber,
            'whitelist_entries_count' => $whitelistEntries->count(),
        ]);

        // Extract country calling code from destination number
        $callingCode = $this->phoneNumberService->extractCallingCode($destinationNumber);
        $countryCode = $callingCode ? $this->phoneNumberService->callingCodeToCountryCode($callingCode) : null;

        Log::info('VoiceRoutingManager: Extracted calling code and country code', [
            'destination_number' => $destinationNumber,
            'calling_code' => $callingCode,
            'country_code' => $countryCode,
        ]);

        $matches = [];

        foreach ($whitelistEntries as $entry) {
            $matchScore = 0;
            $matchReason = '';

            // Check country code match (both ISO codes and calling codes)
            if ($countryCode && $entry->destination_country === $countryCode) {
                $matchScore += 10; // Country match has high priority
                $matchReason .= 'country_match ';
            } elseif ($callingCode && $entry->destination_country === $callingCode) {
                $matchScore += 10; // Calling code match has same high priority
                $matchReason .= 'calling_code_match ';
            } elseif ($callingCode && $entry->destination_country === ltrim($callingCode, '+')) {
                $matchScore += 10; // Calling code without + match has same high priority
                $matchReason .= 'calling_code_no_plus_match ';
            }

            // Check prefix match
            if (! empty($entry->destination_prefix)) {
                // Normalize destination_prefix by removing spaces
                $normalizedPrefix = str_replace(' ', '', $entry->destination_prefix);

                // If prefix starts with +, it's a full international prefix
                if (str_starts_with($normalizedPrefix, '+')) {
                    if (str_starts_with($destinationNumber, $normalizedPrefix)) {
                        $prefixLength = strlen($normalizedPrefix);
                        $matchScore += $prefixLength; // Longer prefixes get higher scores
                        $matchReason .= "full_prefix_match({$prefixLength}) ";
                    }
                } else {
                    // If prefix doesn't start with +, check if it matches within the country
                    // For country-matched entries, check if the number (without country code) starts with prefix
                    if ($countryCode && $entry->destination_country === $countryCode && $callingCode) {
                        $numberWithoutCountryCode = substr($destinationNumber, strlen($callingCode));
                        if (str_starts_with($numberWithoutCountryCode, $normalizedPrefix)) {
                            $prefixLength = strlen($normalizedPrefix);
                            $matchScore += $prefixLength;
                            $matchReason .= "additional_prefix_match({$prefixLength}) ";
                        }
                    }
                    // Also check for direct prefix match (backward compatibility)
                    elseif (str_starts_with($destinationNumber, $normalizedPrefix)) {
                        $prefixLength = strlen($normalizedPrefix);
                        $matchScore += $prefixLength;
                        $matchReason .= "direct_prefix_match({$prefixLength}) ";
                    }
                }
            }

            if ($matchScore > 0) {
                $matches[] = [
                    'entry' => $entry,
                    'score' => $matchScore,
                    'reason' => trim($matchReason),
                ];
            }
        }

        // Sort by score (highest first) and return the best match
        if (! empty($matches)) {
            usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);
            $bestMatch = $matches[0];

            Log::info('VoiceRoutingManager: Outbound whitelist match found', [
                'destination_number' => $destinationNumber,
                'calling_code' => $callingCode,
                'country_code' => $countryCode,
                'matched_country' => $bestMatch['entry']->destination_country,
                'matched_prefix' => $bestMatch['entry']->destination_prefix,
                'outbound_trunk_name' => $bestMatch['entry']->outbound_trunk_name,
                'match_score' => $bestMatch['score'],
                'match_reason' => $bestMatch['reason'],
            ]);

            return $bestMatch['entry'];
        }

        Log::info('VoiceRoutingManager: No outbound whitelist match found', [
            'organization_id' => $organizationId,
            'destination_number' => $destinationNumber,
            'calling_code' => $callingCode,
            'country_code' => $countryCode,
        ]);

        return null;
    }



    /**
     * Convert calling code to country code.
     * Uses a mapping of common calling codes to ISO country codes.
     *
     * @return string|null The ISO country code or null if not found
     */
    private function callingCodeToCountryCode(string $callingCode): ?string
    {
        $callingCodeMap = [
            '+1' => 'US',     // United States, Canada, etc.
            '+7' => 'RU',     // Russia, Kazakhstan
            '+20' => 'EG',    // Egypt
            '+27' => 'ZA',    // South Africa
            '+30' => 'GR',    // Greece
            '+31' => 'NL',    // Netherlands
            '+32' => 'BE',    // Belgium
            '+33' => 'FR',    // France
            '+34' => 'ES',    // Spain
            '+36' => 'HU',    // Hungary
            '+39' => 'IT',    // Italy
            '+40' => 'RO',    // Romania
            '+41' => 'CH',    // Switzerland
            '+43' => 'AT',    // Austria
            '+44' => 'GB',    // United Kingdom
            '+45' => 'DK',    // Denmark
            '+46' => 'SE',    // Sweden
            '+47' => 'NO',    // Norway
            '+48' => 'PL',    // Poland
            '+49' => 'DE',    // Germany
            '+51' => 'PE',    // Peru
            '+52' => 'MX',    // Mexico
            '+53' => 'CU',    // Cuba
            '+54' => 'AR',    // Argentina
            '+55' => 'BR',    // Brazil
            '+56' => 'CL',    // Chile
            '+57' => 'CO',    // Colombia
            '+58' => 'VE',    // Venezuela
            '+60' => 'MY',    // Malaysia
            '+61' => 'AU',    // Australia
            '+62' => 'ID',    // Indonesia
            '+63' => 'PH',    // Philippines
            '+64' => 'NZ',    // New Zealand
            '+65' => 'SG',    // Singapore
            '+66' => 'TH',    // Thailand
            '+81' => 'JP',    // Japan
            '+82' => 'KR',    // South Korea
            '+84' => 'VN',    // Vietnam
            '+86' => 'CN',    // China
            '+90' => 'TR',    // Turkey
            '+91' => 'IN',    // India
            '+92' => 'PK',    // Pakistan
            '+93' => 'AF',    // Afghanistan
            '+94' => 'LK',    // Sri Lanka
            '+95' => 'MM',    // Myanmar
            '+98' => 'IR',    // Iran
            '+212' => 'MA',   // Morocco
            '+213' => 'DZ',   // Algeria
            '+216' => 'TN',   // Tunisia
            '+218' => 'LY',   // Libya
            '+220' => 'GM',   // Gambia
            '+221' => 'SN',   // Senegal
            '+222' => 'MR',   // Mauritania
            '+223' => 'ML',   // Mali
            '+224' => 'GN',   // Guinea
            '+225' => 'CI',   // Ivory Coast
            '+226' => 'BF',   // Burkina Faso
            '+227' => 'NE',   // Niger
            '+228' => 'TG',   // Togo
            '+229' => 'BJ',   // Benin
            '+230' => 'MU',   // Mauritius
            '+231' => 'LR',   // Liberia
            '+232' => 'SL',   // Sierra Leone
            '+233' => 'GH',   // Ghana
            '+234' => 'NG',   // Nigeria
            '+235' => 'TD',   // Chad
            '+236' => 'CF',   // Central African Republic
            '+237' => 'CM',   // Cameroon
            '+238' => 'CV',   // Cape Verde
            '+239' => 'ST',   // Sao Tome and Principe
            '+240' => 'GQ',   // Equatorial Guinea
            '+241' => 'GA',   // Gabon
            '+242' => 'CG',   // Congo
            '+243' => 'CD',   // Democratic Republic of the Congo
            '+244' => 'AO',   // Angola
            '+245' => 'GW',   // Guinea-Bissau
            '+246' => 'IO',   // British Indian Ocean Territory
            '+248' => 'SC',   // Seychelles
            '+249' => 'SD',   // Sudan
            '+250' => 'RW',   // Rwanda
            '+251' => 'ET',   // Ethiopia
            '+252' => 'SO',   // Somalia
            '+253' => 'DJ',   // Djibouti
            '+254' => 'KE',   // Kenya
            '+255' => 'TZ',   // Tanzania
            '+256' => 'UG',   // Uganda
            '+257' => 'BI',   // Burundi
            '+258' => 'MZ',   // Mozambique
            '+260' => 'ZM',   // Zambia
            '+261' => 'MG',   // Madagascar
            '+262' => 'RE',   // Reunion
            '+263' => 'ZW',   // Zimbabwe
            '+264' => 'NA',   // Namibia
            '+265' => 'MW',   // Malawi
            '+266' => 'LS',   // Lesotho
            '+267' => 'BW',   // Botswana
            '+268' => 'SZ',   // Eswatini
            '+269' => 'KM',   // Comoros
            '+290' => 'SH',   // Saint Helena
            '+291' => 'ER',   // Eritrea
            '+297' => 'AW',   // Aruba
            '+298' => 'FO',   // Faroe Islands
            '+299' => 'GL',   // Greenland
            '+350' => 'GI',   // Gibraltar
            '+351' => 'PT',   // Portugal
            '+352' => 'LU',   // Luxembourg
            '+353' => 'IE',   // Ireland
            '+354' => 'IS',   // Iceland
            '+355' => 'AL',   // Albania
            '+356' => 'MT',   // Malta
            '+357' => 'CY',   // Cyprus
            '+358' => 'FI',   // Finland
            '+359' => 'BG',   // Bulgaria
            '+370' => 'LT',   // Lithuania
            '+371' => 'LV',   // Latvia
            '+372' => 'EE',   // Estonia
            '+373' => 'MD',   // Moldova
            '+374' => 'AM',   // Armenia
            '+375' => 'BY',   // Belarus
            '+376' => 'AD',   // Andorra
            '+377' => 'MC',   // Monaco
            '+378' => 'SM',   // San Marino
            '+380' => 'UA',   // Ukraine
            '+381' => 'RS',   // Serbia
            '+382' => 'ME',   // Montenegro
            '+383' => 'XK',   // Kosovo
            '+385' => 'HR',   // Croatia
            '+386' => 'SI',   // Slovenia
            '+387' => 'BA',   // Bosnia and Herzegovina
            '+389' => 'MK',   // North Macedonia
            '+420' => 'CZ',   // Czech Republic
            '+421' => 'SK',   // Slovakia
            '+423' => 'LI',   // Liechtenstein
            '+500' => 'FK',   // Falkland Islands
            '+501' => 'BZ',   // Belize
            '+502' => 'GT',   // Guatemala
            '+503' => 'SV',   // El Salvador
            '+504' => 'HN',   // Honduras
            '+505' => 'NI',   // Nicaragua
            '+506' => 'CR',   // Costa Rica
            '+507' => 'PA',   // Panama
            '+508' => 'PM',   // Saint Pierre and Miquelon
            '+509' => 'HT',   // Haiti
            '+590' => 'GP',   // Guadeloupe
            '+591' => 'BO',   // Bolivia
            '+592' => 'GY',   // Guyana
            '+593' => 'EC',   // Ecuador
            '+594' => 'GF',   // French Guiana
            '+595' => 'PY',   // Paraguay
            '+596' => 'MQ',   // Martinique
            '+597' => 'SR',   // Suriname
            '+598' => 'UY',   // Uruguay
            '+599' => 'CW',   // Curaçao
            '+670' => 'TL',   // Timor-Leste
            '+672' => 'AQ',   // Australian Antarctic Territory
            '+673' => 'BN',   // Brunei
            '+674' => 'NR',   // Nauru
            '+675' => 'PG',   // Papua New Guinea
            '+676' => 'TO',   // Tonga
            '+677' => 'SB',   // Solomon Islands
            '+678' => 'VU',   // Vanuatu
            '+679' => 'FJ',   // Fiji
            '+680' => 'PW',   // Palau
            '+681' => 'WF',   // Wallis and Futuna
            '+682' => 'CK',   // Cook Islands
            '+683' => 'NU',   // Niue
            '+684' => 'AS',   // American Samoa
            '+685' => 'WS',   // Samoa
            '+686' => 'KI',   // Kiribati
            '+687' => 'NC',   // New Caledonia
            '+688' => 'TV',   // Tuvalu
            '+689' => 'PF',   // French Polynesia
            '+690' => 'TK',   // Tokelau
            '+691' => 'FM',   // Micronesia
            '+692' => 'MH',   // Marshall Islands
            '+850' => 'KP',   // North Korea
            '+852' => 'HK',   // Hong Kong
            '+853' => 'MO',   // Macau
            '+855' => 'KH',   // Cambodia
            '+856' => 'LA',   // Laos
            '+880' => 'BD',   // Bangladesh
            '+886' => 'TW',   // Taiwan
            '+960' => 'MV',   // Maldives
            '+961' => 'LB',   // Lebanon
            '+962' => 'JO',   // Jordan
            '+963' => 'SY',   // Syria
            '+964' => 'IQ',   // Iraq
            '+965' => 'KW',   // Kuwait
            '+966' => 'SA',   // Saudi Arabia
            '+967' => 'YE',   // Yemen
            '+968' => 'OM',   // Oman
            '+970' => 'PS',   // Palestine
            '+971' => 'AE',   // United Arab Emirates
            '+972' => 'IL',   // Israel
            '+973' => 'BH',   // Bahrain
            '+974' => 'QA',   // Qatar
            '+975' => 'BT',   // Bhutan
            '+976' => 'MN',   // Mongolia
            '+977' => 'NP',   // Nepal
            '+992' => 'TJ',   // Tajikistan
            '+993' => 'TM',   // Turkmenistan
            '+994' => 'AZ',   // Azerbaijan
            '+995' => 'GE',   // Georgia
            '+996' => 'KG',   // Kyrgyzstan
            '+998' => 'UZ',   // Uzbekistan
        ];

        return $callingCodeMap[$callingCode] ?? null;
    }

    /**
     * Check if a calling code is valid.
     * Basic validation - could be enhanced with a proper phone library.
     */
    private function isValidCallingCode(string $callingCode): bool
    {
        // Remove + and check if remaining is numeric and reasonable length
        $code = ltrim($callingCode, '+');

        return is_numeric($code) && strlen($code) >= 1 && strlen($code) <= 4;
    }

    /**
     * Check business hours and route accordingly.
     *
     * @param  int  $organizationId  The organization ID
     * @param  string  $callSid  The call SID
     * @param  Request  $request  The incoming request
     * @return Response|null CXML response if business hours routing applies, null otherwise
     */
    private function checkBusinessHours(int $organizationId, string $callSid, Request $request): ?Response
    {
        $schedule = $this->cache->getActiveBusinessHoursSchedule($organizationId);

        if (! $schedule) {
            return null;
        }

        $currentAction = $schedule->getCurrentRouting();

        if (! empty($currentAction)) {
            Log::info('VoiceRoutingManager: Routing via business hours action', [
                'call_sid' => $callSid,
                'action' => $currentAction,
                'is_open' => $schedule->isCurrentlyOpen(),
            ]);

            return $this->routeToBusinessHoursAction($currentAction, $organizationId, $callSid, $request);
        }

        return null;
    }

    /**
     * Handle outbound routing via whitelist for calls from internal extensions.
     *
     * @param  Request  $request  The incoming call request
     * @param  string  $to  The destination phone number
     * @param  string  $from  The caller phone number
     * @param  int  $orgId  The organization ID
     * @return Response|null CXML response if outbound routing applies, null otherwise
     */
    private function handleOutboundRouting(Request $request, string $to, string $from, int $orgId): ?Response
    {
        Log::info('VoiceRoutingManager: Checking for outbound whitelist routing', [
            'direction' => $request->input('Direction', 'unknown'),
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        // Only allow outbound routing for calls from internal extensions
        $fromExtension = $this->cache->getExtension($orgId, $from);

        if (! $fromExtension || ! $fromExtension->isActive()) {
            Log::info('VoiceRoutingManager: Outbound routing not allowed - caller is not an active internal extension', [
                'from' => $from,
                'extension_found' => $fromExtension !== null,
                'extension_active' => $fromExtension?->isActive(),
            ]);

            return null;
        }

        Log::info('VoiceRoutingManager: Caller is active internal extension, checking outbound whitelist', [
            'from' => $from,
            'extension_found' => $fromExtension !== null,
            'extension_active' => $fromExtension->isActive(),
        ]);

        // Check if destination is in outbound whitelist
        $whitelistEntry = OutboundWhitelist::where('organization_id', $orgId)
            ->where('phone_number', $to)
            ->first();

        if (! $whitelistEntry) {
            Log::info('VoiceRoutingManager: No outbound whitelist entry found for destination', [
                'to' => $to,
                'org_id' => $orgId,
            ]);

            return null;
        }

        Log::info('VoiceRoutingManager: Outbound whitelist entry found, routing via trunk', [
            'to' => $to,
            'org_id' => $orgId,
            'trunk_id' => $whitelistEntry->trunk_id,
        ]);

        // Route via trunk (not implemented yet)
        return response(CxmlBuilder::unavailable('Outbound routing not yet implemented'), 200, ['Content-Type' => 'text/xml']);
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

            if (! $ivrMenu) {
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

            if (! $callState) {
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

        return $this->executeStrategy(\App\Enums\ExtensionType::IVR, $request, new DidNumber, $destination);
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
        return $ivrStrategy->route($request, new DidNumber, $destination, $errorMessage);
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

            if (! $validatedDestination) {
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

                    // Resolve the extension destination (could be ring group, conference, etc.)
                    $destination = $this->resolveExtensionDestination($validatedDestination, $ivrMenu->organization_id);

                    Log::info('IVR Input: About to execute strategy', [
                        'extension_type' => $validatedDestination->type,
                        'extension_type_value' => $validatedDestination->type->value,
                        'resolved_destination' => $destination,
                    ]);

                    return $this->executeStrategy($validatedDestination->type, $request, new DidNumber, $destination);

                case \App\Enums\IvrDestinationType::RING_GROUP:
                    Log::info('IVR Input: Routing to ring group', [
                        'call_sid' => $request->input('CallSid'),
                        'option_id' => $option->id,
                        'ring_group_id' => $validatedDestination->id,
                        'ring_group_name' => $validatedDestination->name,
                    ]);
                    $destination = ['ring_group' => $validatedDestination];

                    return $this->executeStrategy(\App\Enums\ExtensionType::RING_GROUP, $request, new DidNumber, $destination);

                case \App\Enums\IvrDestinationType::CONFERENCE_ROOM:
                    Log::info('IVR Input: Routing to conference room', [
                        'call_sid' => $request->input('CallSid'),
                        'option_id' => $option->id,
                        'conference_room_id' => $validatedDestination->id,
                        'conference_room_name' => $validatedDestination->name,
                    ]);
                    $destination = ['conference_room' => $validatedDestination];

                    return $this->executeStrategy(\App\Enums\ExtensionType::CONFERENCE, $request, new DidNumber, $destination);

                case \App\Enums\IvrDestinationType::IVR_MENU:
                    Log::info('IVR Input: Routing to IVR menu', [
                        'call_sid' => $request->input('CallSid'),
                        'option_id' => $option->id,
                        'ivr_menu_id' => $validatedDestination->id,
                        'ivr_menu_name' => $validatedDestination->name,
                    ]);
                    $destination = ['ivr_menu' => $validatedDestination];

                    return $this->executeStrategy(\App\Enums\ExtensionType::IVR, $request, new DidNumber, $destination);
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

        if (! $validatedDestination) {
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

            return $this->executeStrategy($validatedDestination->type, $request, new DidNumber, $destination);
        } elseif ($destinationType === \App\Enums\IvrDestinationType::RING_GROUP) {
            $destination = ['ring_group' => $validatedDestination];

            return $this->executeStrategy(\App\Enums\ExtensionType::RING_GROUP, $request, new DidNumber, $destination);
        } elseif ($destinationType === \App\Enums\IvrDestinationType::CONFERENCE_ROOM) {
            $destination = ['conference_room' => $validatedDestination];

            return $this->executeStrategy(\App\Enums\ExtensionType::CONFERENCE, $request, new DidNumber, $destination);
        } elseif ($destinationType === \App\Enums\IvrDestinationType::IVR_MENU) {
            $destination = ['ivr_menu' => $validatedDestination];

            return $this->executeStrategy(\App\Enums\ExtensionType::IVR, $request, new DidNumber, $destination);
        }

        return response(
            CxmlBuilder::sayWithHangup('Unknown destination type.', true),
            200,
            ['Content-Type' => 'text/xml']
        );
    }

    /**
     * Execute the appropriate routing strategy for the given extension type.
     */
    private function executeStrategy(ExtensionType $type, Request $request, DidNumber $did, array $destination): Response
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->canHandle($type)) {
                return $strategy->route($request, $did, $destination);
            }
        }

        Log::error('VoiceRoutingManager: No strategy found for extension type', [
            'type' => $type->value,
            'available_strategies' => $this->strategies->map(fn ($s) => get_class($s))->toArray(),
        ]);

        return $this->createCxmlErrorResponse('Unsupported extension type');
    }

    /**
     * Resolve extension destination based on extension type.
     *
     * Loads the appropriate related models from extension configuration
     * and returns them in a destination array for routing strategies.
     *
     * @param  Extension  $extension  The extension to resolve destination for
     * @param  int  $organizationId  The organization ID for scoping queries
     * @return array The destination array with loaded models
     */
    private function resolveExtensionDestination(Extension $extension, int $organizationId): array
    {
        $config = $extension->configuration ?? [];

        return match ($extension->type) {
            ExtensionType::USER => [
                'extension' => $extension,
            ],
            ExtensionType::RING_GROUP => [
                'ring_group' => $this->loadRingGroupFromExtension($extension, $organizationId),
            ],
            ExtensionType::CONFERENCE => [
                'conference_room' => $this->loadConferenceRoomFromExtension($extension, $organizationId),
            ],
            ExtensionType::IVR => [
                'ivr_menu' => $this->loadIvrMenuFromExtension($extension, $organizationId),
            ],
            ExtensionType::AI_ASSISTANT => [
                'extension' => $extension, // AI assistant logic handled in strategy
            ],
            ExtensionType::FORWARD => [
                'extension' => $extension, // Forwarding logic handled in strategy
            ],
            default => [
                'extension' => $extension, // Fallback to extension for unknown types
            ],
        };
    }

    /**
     * Load ring group from extension configuration.
     * Returns null if the configured ring group doesn't exist.
     */
    private function loadRingGroupFromExtension(Extension $extension, int $organizationId): ?RingGroup
    {
        $config = $extension->configuration ?? [];
        $ringGroupId = $config['ring_group_id'] ?? null;

        if (! $ringGroupId) {
            Log::error('VoiceRoutingManager: RING_GROUP extension missing ring_group_id in configuration', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'extension_type' => $extension->type->value,
                'configuration' => $extension->configuration,
            ]);

            return null;
        }

        $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ringGroupId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $ringGroup) {
            Log::error('VoiceRoutingManager: Configured ring group not found for RING_GROUP extension', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'ring_group_id' => $ringGroupId,
                'organization_id' => $organizationId,
            ]);

            return null;
        }

        return $ringGroup;
    }

    /**
     * Load conference room from extension configuration.
     * Returns null if the configured conference room doesn't exist.
     */
    private function loadConferenceRoomFromExtension(Extension $extension, int $organizationId): ?ConferenceRoom
    {
        $config = $extension->configuration ?? [];
        $conferenceRoomId = $config['conference_room_id'] ?? null;

        if (! $conferenceRoomId) {
            Log::error('VoiceRoutingManager: Extension missing conference_room_id in configuration', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return null;
        }

        $conferenceRoom = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $conferenceRoomId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $conferenceRoom) {
            Log::error('VoiceRoutingManager: Configured conference room not found for extension', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'conference_room_id' => $conferenceRoomId,
            ]);

            return null;
        }

        return $conferenceRoom;
    }

    /**
     * Load IVR menu from extension configuration.
     * Returns null if the configured IVR menu doesn't exist.
     */
    private function loadIvrMenuFromExtension(Extension $extension, int $organizationId): ?IvrMenu
    {
        $config = $extension->configuration ?? [];
        $ivrMenuId = $config['ivr_menu_id'] ?? $config['ivr_id'] ?? null;

        if (! $ivrMenuId) {
            Log::error('VoiceRoutingManager: Extension missing ivr_menu_id in configuration', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return null;
        }

        $ivrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ivrMenuId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $ivrMenu) {
            Log::error('VoiceRoutingManager: Configured IVR menu not found for extension', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'ivr_menu_id' => $ivrMenuId,
            ]);

            return null;
        }

        return $ivrMenu;
    }

    /**
     * Route using extension strategies.
     *
     * Delegates to the appropriate routing strategy based on extension type.
     * Returns error response if referenced entities don't exist.
     *
     * @param  Extension  $extension  The extension being routed
     * @param  array  $destination  The resolved destination resources
     * @param  int  $organizationId  The organization ID
     * @param  Request  $request  The incoming request (added to match strategy interface)
     * @return Response CXML response from the routing strategy or error response
     */
    private function routeUsingExtensionStrategies(Extension $extension, array $destination, int $organizationId, Request $request): Response
    {
        // Check for null referenced entities and return error responses
        if ($extension->type === ExtensionType::RING_GROUP && (!isset($destination['ring_group']) || $destination['ring_group'] === null)) {
            return $this->createCxmlErrorResponse('Ring group not found');
        }

        if ($extension->type === ExtensionType::CONFERENCE && (!isset($destination['conference_room']) || $destination['conference_room'] === null)) {
            return $this->createCxmlErrorResponse('Conference room not found');
        }

        if ($extension->type === ExtensionType::IVR && (!isset($destination['ivr_menu']) || $destination['ivr_menu'] === null)) {
            return $this->createCxmlErrorResponse('IVR menu not found');
        }

        // Create a dummy DID for strategy compatibility
        $dummyDid = new DidNumber;

        return $this->executeStrategy($extension->type, $request, $dummyDid, $destination);
    }

    /**
     * Determine the extension type from a DID destination.
     */
    private function determineExtensionType(DidNumber $did, array $destination): ?ExtensionType
    {
        // If destination contains extension, use its type
        if (isset($destination['extension'])) {
            return $destination['extension']->type;
        }

        // If destination contains ring_group, return RING_GROUP
        if (isset($destination['ring_group'])) {
            return ExtensionType::RING_GROUP;
        }

        // If destination contains conference_room, return CONFERENCE
        if (isset($destination['conference_room'])) {
            return ExtensionType::CONFERENCE;
        }

        // If destination contains ivr_menu, return IVR
        if (isset($destination['ivr_menu'])) {
            return ExtensionType::IVR;
        }

        Log::warning('VoiceRoutingManager: Could not determine extension type from destination', [
            'did_id' => $did->id,
            'destination_keys' => array_keys($destination),
        ]);

        return null;
    }







    /**
     * Validate extension configuration for potential issues.
     *
     * @param  Extension  $extension  The extension to validate
     * @return array{has_issues: bool, extension_number: string, type: string, issues: array, suggestions: array}
     */
    public function validateExtensionConfiguration(Extension $extension): array
    {
        $issues = [];
        $suggestions = [];

        $config = $extension->configuration ?? [];

        // Check configuration based on extension type
        switch ($extension->type) {
            case ExtensionType::RING_GROUP:
                $ringGroupId = $config['ring_group_id'] ?? null;
                if (!$ringGroupId) {
                    $issues[] = 'Missing ring_group_id in configuration';
                    $suggestions[] = 'Add ring_group_id to extension configuration';
                } else {
                    $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
                        ->where('id', $ringGroupId)
                        ->where('organization_id', $extension->organization_id)
                        ->first();
                    if (!$ringGroup) {
                        $issues[] = "Ring group with ID {$ringGroupId} not found";
                        $suggestions[] = 'Create a ring group or update ring_group_id to reference an existing one';
                    }
                }
                break;

            case ExtensionType::CONFERENCE:
                $conferenceRoomId = $config['conference_room_id'] ?? null;
                if (!$conferenceRoomId) {
                    $issues[] = 'Missing conference_room_id in configuration';
                    $suggestions[] = 'Add conference_room_id to extension configuration';
                } else {
                    $conferenceRoom = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
                        ->where('id', $conferenceRoomId)
                        ->where('organization_id', $extension->organization_id)
                        ->first();
                    if (!$conferenceRoom) {
                        $issues[] = "Conference room with ID {$conferenceRoomId} not found";
                        $suggestions[] = 'Create a conference room or update conference_room_id to reference an existing one';
                    }
                }
                break;

            case ExtensionType::IVR:
                $ivrMenuId = $config['ivr_menu_id'] ?? $config['ivr_id'] ?? null;
                if (!$ivrMenuId) {
                    $issues[] = 'Missing ivr_menu_id or ivr_id in configuration';
                    $suggestions[] = 'Add ivr_menu_id or ivr_id to extension configuration';
                } else {
                    $ivrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
                        ->where('id', $ivrMenuId)
                        ->where('organization_id', $extension->organization_id)
                        ->first();
                    if (!$ivrMenu) {
                        $issues[] = "IVR menu with ID {$ivrMenuId} not found";
                        $suggestions[] = 'Create an IVR menu or update ivr_menu_id/ivr_id to reference an existing one';
                    }
                }
                break;

            case ExtensionType::USER:
            case ExtensionType::AI_ASSISTANT:
            case ExtensionType::FORWARD:
                // These types don't require additional configuration validation
                break;
        }

        return [
            'has_issues' => !empty($issues),
            'extension_number' => $extension->extension_number,
            'type' => $extension->type->value,
            'issues' => $issues,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Create a CXML error response for voice routing failures.
     *
     * @param  string  $message  The error message to speak
     * @return Response CXML response with error message and hangup
     */
    private function createCxmlErrorResponse(string $message): Response
    {
        return response(CxmlBuilder::sayWithHangup($message, true), 200, ['Content-Type' => 'text/xml']);
    }
}
