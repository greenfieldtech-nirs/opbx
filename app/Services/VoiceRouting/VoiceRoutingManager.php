<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Scopes\OrganizationScope;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\InboundBlacklist\InboundBlacklistService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Voice Routing Manager
 *
 * Central coordinator for handling inbound voice call routing in the PBX system.
 * Delegates specific routing logic to specialized services:
 *
 * - InboundRoutingService: DID-based routing and business hours
 * - OutboundRoutingService: Outbound whitelist and trunk selection
 * - ExtensionRoutingService: Extension lookup and validation
 * - IvrRoutingService: IVR menu processing and state management
 * - RingGroupRoutingService: Ring group callback handling
 * - VoiceRoutingStrategyExecutor: Strategy pattern execution
 *
 * This class maintains the public API surface while delegating implementation
 * details to focused, single-responsibility services.
 */
class VoiceRoutingManager
{
    public function __construct(
        private readonly VoiceRoutingCacheService $cache,
        private readonly InboundBlacklistService $inboundBlacklistService,
        private readonly OutboundRoutingService $outboundRouting,
        private readonly BusinessHoursRoutingService $businessHoursRouting,
        private readonly InboundRoutingService $inboundRouting,
        private readonly ExtensionRoutingService $extensionRouting,
        private readonly IvrRoutingService $ivrRouting,
        private readonly RingGroupRoutingService $ringGroupRouting,
        private readonly VoiceRoutingStrategyExecutor $strategyExecutor,
        private readonly CoachRoutingService $coachRouting
    ) {}

    /**
     * Handle inbound call routing.
     *
     * Main entry point for processing incoming calls. Determines the routing path
     * based on the call direction and delegates to appropriate handlers.
     *
     * @param  Request  $request  The incoming HTTP request containing call details
     * @return Response CXML response with routing instructions
     */
    public function handleInbound(Request $request): Response
    {
        $coachResponse = $this->coachRouting->tryHandle($request);
        if ($coachResponse !== null) {
            return $coachResponse;
        }

        $direction = $request->input('Direction', 'unknown');
        $to = $request->input('To');
        $from = $request->input('From');
        $orgId = (int) $request->input('_organization_id');

        Log::info('VoiceRoutingManager: Handling call routing', [
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

        Log::info('VoiceRoutingManager: Subscriber direction call', [
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
        $didResponse = $this->inboundRouting->handleDidRouting($request, $to, $orgId);
        if ($didResponse) {
            return $didResponse;
        }

        // 3. Try outbound routing (for calls to external numbers)
        $outboundResponse = $this->handleOutboundRouting($request, $to, $from, $orgId);
        if ($outboundResponse) {
            return $outboundResponse;
        }

        Log::info('VoiceRoutingManager: No destination found for subscriber call', [
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        return $this->createErrorResponse('Extension not found');
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

        Log::info('VoiceRoutingManager: Inbound direction call', [
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        // Validate required parameters
        if (empty($to) || empty($from)) {
            Log::warning('VoiceRoutingManager: Missing required call parameters', [
                'to' => $to,
                'from' => $from,
            ]);

            return $this->createErrorResponse('Missing call parameters');
        }

        // Check blacklist for external calls
        $did = DidNumber::withoutGlobalScope(OrganizationScope::class)
            ->where('phone_number', $to)
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->first();

        if ($did) {
            $blacklistCheck = $this->inboundBlacklistService->isBlacklisted($from, $did->id, $orgId);
            if ($blacklistCheck) {
                Log::info('VoiceRoutingManager: Caller is blacklisted', [
                    'from' => $from,
                    'did_id' => $did->id,
                ]);

                return $this->inboundBlacklistService->generateRejectionCxml($blacklistCheck, $request);
            }
        }

        // Determine call type: check if From is an assigned phone number
        $isFromAssigned = $this->inboundRouting->isAssignedPhoneNumber($from, $orgId);

        Log::info('VoiceRoutingManager: Call type determination', [
            'from' => $from,
            'is_from_assigned' => $isFromAssigned,
        ]);

        if ($isFromAssigned) {
            return $this->handleInternalCallFromDid($request);
        }

        return $this->handleExternalInboundCall($request);
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

        Log::debug('VoiceRoutingManager: Processing internal call from DID', [
            'from_did' => $from,
            'to' => $to,
        ]);

        // 1. Try extension routing first (direct extension dial)
        $extensionResponse = $this->handleExtensionRouting($request, $to, $orgId);
        if ($extensionResponse) {
            return $extensionResponse;
        }

        // 2. Try DID routing (fallback for internal calls)
        $didResponse = $this->inboundRouting->handleDidRouting($request, $to, $orgId);
        if ($didResponse) {
            return $didResponse;
        }

        // 3. Try outbound routing (for calls to external numbers)
        $outboundResponse = $this->handleOutboundRouting($request, $to, $from, $orgId);
        if ($outboundResponse) {
            return $outboundResponse;
        }

        Log::info('VoiceRoutingManager: No destination found for internal DID call', [
            'from_did' => $from,
            'to' => $to,
        ]);

        return $this->createErrorResponse('Extension not found');
    }

    /**
     * Handle external inbound calls to a DID.
     *
     * These are calls from external numbers to an assigned DID. Routes ONLY to the
     * configured destination for that specific DID.
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
        ]);

        // Route ONLY to the configured destination for this specific DID
        $didResponse = $this->inboundRouting->handleDidRouting($request, $to, $orgId);
        if ($didResponse) {
            return $didResponse;
        }

        Log::info('VoiceRoutingManager: No destination configured for DID', [
            'from_external' => $from,
            'to_did' => $to,
        ]);

        return $this->createErrorResponse('DID destination not configured');
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

        Log::debug('VoiceRoutingManager: Outbound direction call', [
            'to' => $to,
            'from' => $from,
        ]);

        // Try outbound routing via whitelist
        $outboundResponse = $this->handleOutboundRouting($request, $to, $from, $orgId);
        if ($outboundResponse) {
            return $outboundResponse;
        }

        Log::info('VoiceRoutingManager: Outbound call not allowed', [
            'to' => $to,
            'from' => $from,
        ]);

        return $this->createErrorResponse('Outbound call not permitted');
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

        Log::debug('VoiceRoutingManager: Application direction call', [
            'to' => $to,
            'from' => $from,
        ]);

        // Use the same logic as subscriber direction calls
        // 1. Try extension routing first (direct extension dial)
        $extensionResponse = $this->handleExtensionRouting($request, $to, $orgId);
        if ($extensionResponse) {
            return $extensionResponse;
        }

        // 2. Try DID routing (fallback for API-initiated calls)
        $didResponse = $this->inboundRouting->handleDidRouting($request, $to, $orgId);
        if ($didResponse) {
            return $didResponse;
        }

        // 3. Try outbound routing (for calls to external numbers)
        $outboundResponse = $this->handleOutboundRouting($request, $to, $from, $orgId);
        if ($outboundResponse) {
            return $outboundResponse;
        }

        Log::info('VoiceRoutingManager: No destination found for application call', [
            'to' => $to,
            'from' => $from,
        ]);

        return $this->createErrorResponse('Extension not found');
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
        Log::warning('VoiceRoutingManager: Unknown call direction, falling back', [
            'direction' => $direction,
        ]);

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
        Log::debug('VoiceRoutingManager: Checking for extension', [
            'to' => $to,
            'org_id' => $orgId,
        ]);

        $extension = $this->extensionRouting->findExtension($orgId, $to);

        if (! $extension) {
            return null;
        }

        Log::debug('VoiceRoutingManager: Found active extension', [
            'extension' => $extension->extension_number,
            'type' => $extension->type->value,
        ]);

        // Resolve the destination based on extension type
        $destination = $this->extensionRouting->resolveExtensionDestination($extension, $orgId);

        // Validate required entities are present
        $validationError = $this->extensionRouting->validateDestinationEntities($extension->type, $destination);
        if ($validationError) {
            return $this->createErrorResponse($validationError);
        }

        // Execute the appropriate strategy
        return $this->strategyExecutor->executeStrategy(
            $extension->type,
            $request,
            new DidNumber,
            $destination
        );
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
        return $this->outboundRouting->handleOutboundRouting($request, $to, $from, $orgId);
    }

    /**
     * Handle IVR DTMF input from caller.
     *
     * @param  Request  $request  The incoming webhook request
     * @return Response CXML response with next routing instruction
     */
    public function handleIvrInput(Request $request): Response
    {
        return $this->ivrRouting->handleIvrInput($request);
    }

    /**
     * Handle ring group sequential callback routing.
     *
     * @param  Request  $request  The incoming callback request
     * @return Response CXML response with next routing instruction
     */
    public function routeRingGroupCallback(Request $request): Response
    {
        return $this->ringGroupRouting->handleRingGroupCallback($request);
    }

    /**
     * Execute the appropriate routing strategy for the given extension type.
     *
     * @param  ExtensionType  $type  The extension type to route
     * @param  Request  $request  The incoming webhook request
     * @param  DidNumber  $did  The DID number being called
     * @param  array<string, mixed>  $destination  The resolved destination resources
     * @return Response CXML response from the routing strategy
     */
    public function executeStrategy(
        ExtensionType $type,
        Request $request,
        DidNumber $did,
        array $destination
    ): Response {
        return $this->strategyExecutor->executeStrategy($type, $request, $did, $destination);
    }

    /**
     * Validate extension configuration for potential issues.
     *
     * @param  Extension  $extension  The extension to validate
     * @return array<string, mixed> Validation result with issues and suggestions
     */
    public function validateExtensionConfiguration(Extension $extension): array
    {
        return $this->extensionRouting->validateExtensionConfiguration($extension);
    }

    /**
     * Create a CXML error response for voice routing failures.
     *
     * @param  string  $message  The error message to speak
     * @return Response CXML response with error message and hangup
     */
    private function createErrorResponse(string $message): Response
    {
        return response(
            CxmlBuilder::sayWithHangup($message, true),
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
