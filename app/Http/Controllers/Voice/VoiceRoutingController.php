<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\BusinessHoursSchedule;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\VoiceRoutingCacheService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Voice Routing Controller
 *
 * Handles real-time call routing decisions from Cloudonix CPaaS platform.
 * Returns CXML (Cloudonix XML) documents that instruct how to route calls
 * based on organizational configuration.
 *
 * Expected webhook parameters from Cloudonix:
 * - From: Caller's phone number (E.164 format)
 * - To: Dialed number / DNID (E.164 format)
 * - CallSid: Unique call identifier
 * - Session: Cloudonix session identifier
 * - Domain: Tenant domain/identifier
 * - SessionData: Optional JSON data passed through call flow
 */
class VoiceRoutingController extends Controller
{
    /**
     * Constructor
     *
     * @param VoiceRoutingCacheService $cache Voice routing cache service
     */
    public function __construct(
        private readonly VoiceRoutingCacheService $cache
    ) {
    }

    /**
     * Handle inbound call routing
     *
     * This is the main entry point for all inbound calls from Cloudonix.
     * It determines how to route the call based on the dialed number (To)
     * and the organization's configuration.
     *
     * @param Request $request
     * @return Response
     */
    public function handleInbound(Request $request): Response
    {
        $callSid = $request->input('CallSid');
        $from = $request->input('From');
        $to = $request->input('To');
        $domain = $request->input('Domain');
        $organizationId = $request->input('_organization_id'); // Set by middleware

        Log::info('Voice routing: Inbound call received', [
            'call_sid' => $callSid,
            'from' => $from,
            'to' => $to,
            'domain' => $domain,
            'organization_id' => $organizationId,
        ]);

        // If organization not identified by middleware, we can't route
        if (!$organizationId) {
            Log::warning('Voice routing: No organization ID found', [
                'call_sid' => $callSid,
                'to' => $to,
            ]);
            return $this->cxmlResponse(CxmlBuilder::simpleHangup());
        }

        // Step 7: Check business hours (if configured)
        $businessHoursResponse = $this->checkBusinessHours($organizationId, $callSid);
        if ($businessHoursResponse) {
            return $businessHoursResponse;
        }

        // Classify call according to section 3.2.0 of CORE_ROUTING_SPECIFICATION.md
        $callType = $this->classifyCall($from, $to, $organizationId);

        Log::info('Voice routing: Call classified', [
            'call_sid' => $callSid,
            'call_type' => $callType,
        ]);

        // If call doesn't match internal or external rules, check for security violations
        if ($callType === 'invalid') {
            // Security check: Detect if external caller is trying to dial E.164 number
            // This is a potential security violation (toll fraud attempt)
            $normalizedFrom = $this->normalizePhoneNumber($from);
            $normalizedTo = $this->normalizePhoneNumber($to);

            // Step 8: Check if From is NOT an internal extension (with caching)
            $fromExtension = $this->cache->getExtension($organizationId, $normalizedFrom);

            // Apply filters
            $fromExtensionExists = $fromExtension &&
                in_array($fromExtension->type, [ExtensionType::USER, ExtensionType::AI_ASSISTANT], true) &&
                $fromExtension->isActive();

            // If external caller is trying to reach E.164 number: security violation
            if (!$fromExtensionExists && $this->isE164($normalizedTo)) {
                Log::warning('Voice routing: Security violation - external caller attempting E.164 dial', [
                    'call_sid' => $callSid,
                    'from' => $from,
                    'to' => $to,
                    'organization_id' => $organizationId,
                    'reason' => 'security_violation_e164',
                ]);

                return $this->cxmlResponse(CxmlBuilder::unavailable('Security violation, no outbound dialing allowed'));
            }

            // Other invalid scenarios: silent hangup
            return $this->cxmlResponse(CxmlBuilder::simpleHangup());
        }

        // Phase 1: Route internal calls (extension-to-extension)
        if ($callType === 'internal') {
            return $this->routeInternalCall($from, $to, $organizationId, $callSid);
        }

        // Phase 0: Return placeholder CXML for external calls (not yet implemented)
        $message = sprintf(
            'Hello. This is the Open PBX voice routing system. Phase zero placeholder response. Call type: %s.',
            $callType ?? 'unknown'
        );

        return $this->cxmlResponse(CxmlBuilder::sayWithHangup($message, true));
    }

    /**
     * Handle IVR digit input
     *
     * Processes DTMF digits collected from an IVR menu and routes
     * the call based on the selected option.
     *
     * @param Request $request
     * @return Response
     */
    public function handleIvrInput(Request $request): Response
    {
        $callSid = $request->input('CallSid');
        $digits = $request->input('Digits');
        $ivrId = $request->input('SessionData.ivr_id');

        Log::info('Voice routing: IVR input received', [
            'call_sid' => $callSid,
            'digits' => $digits,
            'ivr_id' => $ivrId,
        ]);

        // Phase 0: Return placeholder CXML
        // Phase 5+: Implement IVR routing logic
        $message = 'Hello. This is the Open PBX voice routing system. Phase zero placeholder response. Call type: unknown.';
        return $this->cxmlResponse(CxmlBuilder::sayWithHangup($message, true));
    }

    /**
     * Handle ring group callback
     *
     * Called by Cloudonix after each ring attempt in sequential routing
     * strategies (round robin, priority, longest idle). Determines the
     * next extension to try based on the ring group strategy.
     *
     * @param Request $request
     * @return Response
     */
    public function handleRingGroupCallback(Request $request): Response
    {
        $callSid = $request->input('CallSid');
        $ringGroupId = $request->input('SessionData.ring_group_id');
        $attemptNumber = $request->input('SessionData.attempt_number', 1);
        $dialCallStatus = $request->input('DialCallStatus');

        Log::info('Voice routing: Ring group callback received', [
            'call_sid' => $callSid,
            'ring_group_id' => $ringGroupId,
            'attempt_number' => $attemptNumber,
            'dial_call_status' => $dialCallStatus,
        ]);

        // Phase 0: Return placeholder CXML
        // Phase 4+: Implement sequential ring group logic
        $message = 'Hello. This is the Open PBX voice routing system. Phase zero placeholder response. Call type: unknown.';
        return $this->cxmlResponse(CxmlBuilder::sayWithHangup($message, true));
    }

    /**
     * Health check endpoint
     *
     * Returns the health status of the voice routing service.
     * Used by monitoring systems and load balancers.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function health(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'voice-routing',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Classify call as internal, external, or invalid
     *
     * According to CORE_ROUTING_SPECIFICATION.md section 3.2.0:
     *
     * Internal call (PBX User to anywhere):
     * - From = extension number assigned to "PBX User"
     * - From = extension number assigned to "AI Assistant"
     * - To = any internal extension number OR valid E.164 formatted number
     *
     * External call (outside world to valid E.164 number):
     * - From ≠ any application Extensions
     * - To = an application defined "Phone Number"
     *
     * @param string $from
     * @param string $to
     * @param int $organizationId
     * @return string 'internal', 'external', or 'invalid'
     */
    private function classifyCall(string $from, string $to, int $organizationId): string
    {
        // Normalize phone numbers
        $normalizedFrom = $this->normalizePhoneNumber($from);
        $normalizedTo = $this->normalizePhoneNumber($to);

        // Step 8: Check if From is an internal extension (with caching)
        $fromExtension = $this->cache->getExtension($organizationId, $normalizedFrom);

        // Filter by type and status (cache returns all, we need to filter)
        if ($fromExtension &&
            !in_array($fromExtension->type, [ExtensionType::USER, ExtensionType::AI_ASSISTANT], true)) {
            $fromExtension = null;
        }
        if ($fromExtension && !$fromExtension->isActive()) {
            $fromExtension = null;
        }

        // Step 8: Check if To is an internal extension (with caching)
        $toExtension = $this->cache->getExtension($organizationId, $normalizedTo);

        // Filter by status (cache returns all, we need active only)
        if ($toExtension && !$toExtension->isActive()) {
            $toExtension = null;
        }

        // Check if To is a DID (Phone Number)
        $toDid = DidNumber::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('phone_number', $normalizedTo)
            ->where('status', 'active')
            ->first();

        // Internal call: From is a PBX User/AI Assistant extension
        if ($fromExtension && ($toExtension || $this->isE164($normalizedTo))) {
            return 'internal';
        }

        // External call: From is NOT an extension AND To is a DID
        if (!$fromExtension && $toDid) {
            return 'external';
        }

        // Call doesn't match any valid pattern
        return 'invalid';
    }

    /**
     * Route internal call (extension-to-extension or extension-to-E.164)
     *
     * Phase 1 Step 1: Basic extension-to-extension routing
     * - Look up destination extension
     * - Validate it exists and is active
     * - Generate Dial CXML
     *
     * @param string $from
     * @param string $to
     * @param int $organizationId
     * @param string $callSid
     * @return Response
     */
    private function routeInternalCall(string $from, string $to, int $organizationId, string $callSid): Response
    {
        $normalizedFrom = $this->normalizePhoneNumber($from);
        $normalizedTo = $this->normalizePhoneNumber($to);

        Log::info('Voice routing: Routing internal call', [
            'call_sid' => $callSid,
            'from' => $from,
            'to' => $to,
            'normalized_from' => $normalizedFrom,
            'normalized_to' => $normalizedTo,
            'organization_id' => $organizationId,
        ]);

        // Step 4 & 8: Tenant Isolation - Verify FROM extension belongs to this organization (with caching)
        // This provides defense-in-depth against middleware bypass or misconfiguration
        $fromExtension = $this->cache->getExtension($organizationId, $normalizedFrom);

        // Apply filters (cache returns all, we need specific criteria)
        if ($fromExtension &&
            !in_array($fromExtension->type, [ExtensionType::USER, ExtensionType::AI_ASSISTANT], true)) {
            $fromExtension = null;
        }
        if ($fromExtension && !$fromExtension->isActive()) {
            $fromExtension = null;
        }

        if (!$fromExtension) {
            // This should never happen if middleware works correctly, but log as security event
            Log::error('Voice routing: SECURITY - FROM extension not in organization', [
                'call_sid' => $callSid,
                'from' => $from,
                'normalized_from' => $normalizedFrom,
                'organization_id' => $organizationId,
                'reason' => 'tenant_isolation_violation',
                'severity' => 'CRITICAL',
            ]);

            return $this->cxmlResponse(CxmlBuilder::unavailable('Call routing error. Please contact support.'));
        }

        Log::info('Voice routing: FROM extension validated for organization', [
            'call_sid' => $callSid,
            'from_extension_id' => $fromExtension->id,
            'from_extension_number' => $fromExtension->extension_number,
            'organization_id' => $organizationId,
        ]);

        // Step 5: Check if destination is E.164 format (extension dialing out)
        if ($this->isE164($normalizedTo)) {
            Log::info('Voice routing: Extension attempting outbound call to E.164 number', [
                'call_sid' => $callSid,
                'from_extension' => $normalizedFrom,
                'from_extension_type' => $fromExtension->type->value,
                'to_number' => $normalizedTo,
                'organization_id' => $organizationId,
            ]);

            // Check if this extension type is allowed to make outbound calls
            // Only PBX User extensions can make outbound calls by default
            if (!$fromExtension->type->canMakeOutboundCalls()) {
                Log::warning('Voice routing: Outbound calling not allowed for extension type', [
                    'call_sid' => $callSid,
                    'from_extension' => $normalizedFrom,
                    'extension_type' => $fromExtension->type->value,
                    'to_number' => $normalizedTo,
                    'organization_id' => $organizationId,
                    'reason' => 'extension_type_cannot_make_outbound_calls',
                ]);

                return $this->cxmlResponse(CxmlBuilder::unavailable('Your extension type is not permitted to make outbound calls.'));
            }

            // Validate E.164 number format is correct
            if (!$this->validateE164Number($normalizedTo)) {
                Log::warning('Voice routing: Invalid E.164 number format', [
                    'call_sid' => $callSid,
                    'from_extension' => $normalizedFrom,
                    'to_number' => $normalizedTo,
                    'organization_id' => $organizationId,
                    'reason' => 'invalid_e164_format',
                ]);

                return $this->cxmlResponse(CxmlBuilder::unavailable('The phone number you dialed is invalid. Please check the number and try again.'));
            }

            // Log successful outbound routing
            Log::info('Voice routing: Routing outbound call', [
                'call_sid' => $callSid,
                'from_extension' => $normalizedFrom,
                'from_extension_type' => $fromExtension->type->value,
                'caller_id' => $from,
                'to_number' => $normalizedTo,
                'organization_id' => $organizationId,
                'call_type' => 'outbound',
            ]);

            // Generate Dial CXML for outbound call
            return $this->cxmlResponse(CxmlBuilder::simpleDial($normalizedTo, $from));
        }

        // Step 4 & 8: Look up destination extension with caching
        // Eager load user relationship for Step 3 validation
        // Tenant Isolation - Load destination extension scoped to organization
        $destinationExtension = $this->cache->getExtension($organizationId, $normalizedTo);

        // Validation Step 1: Check if extension exists (enforces tenant isolation)
        if (!$destinationExtension) {
            Log::warning('Voice routing: Destination extension not found or not in organization', [
                'call_sid' => $callSid,
                'to' => $normalizedTo,
                'organization_id' => $organizationId,
                'reason' => 'extension_not_found_or_tenant_isolation',
            ]);

            return $this->cxmlResponse(CxmlBuilder::unavailable('The extension number you are trying to reach is invalid, please try again.'));
        }

        // Step 4: Log successful tenant isolation for destination
        Log::info('Voice routing: Destination extension validated for organization', [
            'call_sid' => $callSid,
            'to_extension_id' => $destinationExtension->id,
            'to_extension_number' => $destinationExtension->extension_number,
            'organization_id' => $organizationId,
            'tenant_isolation' => 'enforced',
        ]);

        // Validation Step 2: Check if extension is active (use enum comparison)
        if (!$destinationExtension->isActive()) {
            Log::warning('Voice routing: Destination extension is inactive', [
                'call_sid' => $callSid,
                'to' => $normalizedTo,
                'extension_id' => $destinationExtension->id,
                'status' => $destinationExtension->status->value,
                'organization_id' => $organizationId,
                'reason' => 'extension_inactive',
            ]);

            return $this->cxmlResponse(CxmlBuilder::unavailable('The extension number you are trying to reach is disabled, goodbye.'));
        }

        // Validation Step 3: Check extension type (Step 2: only support 'user' type for now)
        if ($destinationExtension->type !== ExtensionType::USER) {
            Log::warning('Voice routing: Extension type not supported in Step 2', [
                'call_sid' => $callSid,
                'to' => $normalizedTo,
                'extension_id' => $destinationExtension->id,
                'type' => $destinationExtension->type->value,
                'organization_id' => $organizationId,
                'reason' => 'unsupported_extension_type',
            ]);

            return $this->cxmlResponse(CxmlBuilder::unavailable('The extension you are trying to reach is not available at this time.'));
        }

        // Validation Step 4 (Phase 1 Step 3): Check if user is assigned to extension
        if (!$destinationExtension->user) {
            Log::warning('Voice routing: Extension has no user assigned', [
                'call_sid' => $callSid,
                'to' => $normalizedTo,
                'extension_id' => $destinationExtension->id,
                'organization_id' => $organizationId,
                'reason' => 'no_user_assigned',
            ]);

            return $this->cxmlResponse(CxmlBuilder::unavailable('This extension is not associated with any user. Please associate the extension and try again.'));
        }

        // Validation Step 5 (Phase 1 Step 3): Check if assigned user is active
        if (!$destinationExtension->user->isActive()) {
            Log::warning('Voice routing: Extension user is inactive', [
                'call_sid' => $callSid,
                'to' => $normalizedTo,
                'extension_id' => $destinationExtension->id,
                'user_id' => $destinationExtension->user->id,
                'user_status' => $destinationExtension->user->status->value,
                'organization_id' => $organizationId,
                'reason' => 'user_inactive',
            ]);

            return $this->cxmlResponse(CxmlBuilder::unavailable('The user you are trying to reach is currently unavailable.'));
        }

        Log::info('Voice routing: Extension and user validated successfully, generating Dial CXML', [
            'call_sid' => $callSid,
            'extension_id' => $destinationExtension->id,
            'extension_number' => $destinationExtension->extension_number,
            'extension_type' => $destinationExtension->type->value,
            'extension_status' => $destinationExtension->status->value,
            'user_id' => $destinationExtension->user->id,
            'user_status' => $destinationExtension->user->status->value,
        ]);

        // Generate Dial CXML for internal call
        return $this->cxmlResponse(CxmlBuilder::simpleDial($normalizedTo, $from));
    }

    /**
     * Normalize phone number by removing common formatting
     *
     * @param string|null $number
     * @return string
     */
    private function normalizePhoneNumber(?string $number): string
    {
        if (!$number) {
            return '';
        }

        // Remove all non-digit characters except +
        $normalized = preg_replace('/[^0-9+]/', '', $number);

        return $normalized ?? '';
    }

    /**
     * Check if a number is in valid E.164 format
     *
     * E.164 format: +[country code][subscriber number]
     * Length: 7-15 digits (excluding +)
     *
     * @param string $number
     * @return bool
     */
    private function isE164(string $number): bool
    {
        // Must start with +
        if (!str_starts_with($number, '+')) {
            return false;
        }

        // Remove + and check if remaining is all digits
        $digits = substr($number, 1);
        if (!ctype_digit($digits)) {
            return false;
        }

        // Check length (7-15 digits after +)
        $length = strlen($digits);
        return $length >= 7 && $length <= 15;
    }

    /**
     * Validate E.164 number with additional checks beyond format
     *
     * Performs additional validation to reject obviously invalid numbers
     * that may pass basic E.164 format checks but are not dialable.
     *
     * @param string $number
     * @return bool
     */
    private function validateE164Number(string $number): bool
    {
        // Must pass basic E.164 format check first
        if (!$this->isE164($number)) {
            return false;
        }

        $digits = substr($number, 1); // Remove +

        // Reject obviously invalid patterns
        // All zeros
        if (preg_match('/^0+$/', $digits)) {
            return false;
        }

        // All same digit (e.g., +11111111111)
        if (preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }

        // Country code cannot be 0
        if (str_starts_with($digits, '0')) {
            return false;
        }

        // Check minimum length per region (country code + subscriber)
        // Most valid international numbers are at least 8 digits
        if (strlen($digits) < 8) {
            return false;
        }

        return true;
    }

    /**
     * Convert CXML string to HTTP Response
     *
     * @param string $cxml CXML content
     * @return Response
     */
    private function cxmlResponse(string $cxml): Response
    {
        return response($cxml, 200)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Check business hours and return closed message if outside hours
     *
     * Phase 1 Step 7: Basic Business Hours Check
     * - Load organization's active business hours schedule
     * - If schedule exists and business is closed, return "we are closed" message
     * - If no schedule or business is open, return null (proceed with normal routing)
     *
     * @param int $organizationId
     * @param string $callSid
     * @return Response|null
     */
    private function checkBusinessHours(int $organizationId, string $callSid): ?Response
    {
        // Step 8: Load the organization's active business hours schedule from cache
        // Organizations can have multiple schedules, but we use the first active one
        $businessHoursSchedule = $this->cache->getActiveBusinessHoursSchedule($organizationId);

        // If no business hours schedule configured, proceed with normal routing
        if (!$businessHoursSchedule) {
            Log::info('Voice routing: No business hours schedule configured, proceeding with normal routing', [
                'call_sid' => $callSid,
                'organization_id' => $organizationId,
            ]);
            return null;
        }

        Log::info('Voice routing: Checking business hours', [
            'call_sid' => $callSid,
            'organization_id' => $organizationId,
            'schedule_id' => $businessHoursSchedule->id,
            'schedule_name' => $businessHoursSchedule->name,
        ]);

        // Check if currently open
        $isOpen = $businessHoursSchedule->isCurrentlyOpen();

        Log::info('Voice routing: Business hours check result', [
            'call_sid' => $callSid,
            'organization_id' => $organizationId,
            'schedule_id' => $businessHoursSchedule->id,
            'is_open' => $isOpen,
            'current_status' => $businessHoursSchedule->current_status,
        ]);

        // If business is closed, return "we are closed" message
        if (!$isOpen) {
            Log::info('Voice routing: Business is closed, returning closed message', [
                'call_sid' => $callSid,
                'organization_id' => $organizationId,
                'schedule_id' => $businessHoursSchedule->id,
            ]);

            $closedMessage = 'Thank you for calling. We are currently closed. Please call back during our business hours.';

            return $this->cxmlResponse(CxmlBuilder::unavailable($closedMessage));
        }

        // Business is open, proceed with normal routing
        Log::info('Voice routing: Business is open, proceeding with normal routing', [
            'call_sid' => $callSid,
            'organization_id' => $organizationId,
            'schedule_id' => $businessHoursSchedule->id,
        ]);

        return null;
    }

}
