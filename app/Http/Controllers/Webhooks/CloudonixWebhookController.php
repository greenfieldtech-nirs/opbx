<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\Webhook\WebhookBusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesWebhookErrors;
use App\Http\Requests\Webhook\CallInitiatedRequest;
use App\Http\Requests\Webhook\CdrRequest;
use App\Jobs\ProcessInboundCallJob;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\SessionUpdate;
use App\Services\CallRouting\CallRoutingService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles incoming webhooks from Cloudonix CPaaS.
 *
 * @see https://developers.cloudonix.com/Documentation/make.com/webhooks
 */
class CloudonixWebhookController extends Controller
{
    use HandlesWebhookErrors;

    public function __construct(
        private readonly CallRoutingService $routingService
    ) {}

    /**
     * Handle inbound call initiated webhook.
     * This is called by Cloudonix when an inbound call arrives.
     *
     * @see https://developers.cloudonix.com/Documentation/voiceApplication/requestParameters
     */
    public function callInitiated(CallInitiatedRequest $request): Response
    {
        $callId = $request->input('CallSid') ?? $request->input('call_id');
        $from = $request->input('From') ?? $request->input('from');
        $to = $request->input('To') ?? $request->input('to');

        Log::info('Received call-initiated webhook', [
            'call_id' => $callId,
            'from' => $from,
            'to' => $to,
            'payload' => $request->all(),
        ]);

        // Normalize phone numbers
        $fromNumber = $this->normalizePhoneNumber($from);
        $toNumber = $this->normalizePhoneNumber($to);

        // Determine call direction and find organization
        $direction = $request->input('Direction') ?? 'unknown';
        $organizationId = null;
        $organization = null;

        // For internal calls (subscriber direction), check extensions first
        if ($direction === 'subscriber') {
            // Find organization by checking if extension exists within any organization
            $extension = \App\Models\Extension::with('organization:id,name,status')
                ->where('extension_number', $toNumber)
                ->where('status', 'active')
                ->first();

            if ($extension && $extension->organization) {
                $organization = $extension->organization;
                $organizationId = $organization->id;

                Log::info('Found organization via extension for internal call', [
                    'call_id' => $callId,
                    'to_number' => $toNumber,
                    'organization_id' => $organizationId,
                    'extension_id' => $extension->id,
                    'extension_type' => $extension->type->value,
                ]);
            }
        }

        // If no organization found via extension, try DID-based lookup (external calls)
        if (! $organizationId) {
            $didNumber = DidNumber::with('organization:id,name,status')
                ->where('phone_number', $toNumber)
                ->where('status', 'active')
                ->first();

            if ($didNumber && $didNumber->organization) {
                $organization = $didNumber->organization;
                $organizationId = $organization->id;

                Log::info('Found organization via DID for external call', [
                    'call_id' => $callId,
                    'to_number' => $toNumber,
                    'organization_id' => $organizationId,
                    'did_id' => $didNumber->id,
                ]);
            }
        }

        // Validate organization was found
        if (! $organizationId || ! $organization) {
            Log::warning('No organization found for call (neither extension nor DID)', [
                'call_id' => $callId,
                'to_number' => $toNumber,
                'from' => $fromNumber,
                'direction' => $direction,
            ]);

            return response(
                '<Response><Say>This number is not configured.</Say><Hangup/></Response>',
                200
            )->header('Content-Type', 'application/xml');
        }

        // Validate organization is active
        if ($organization->status !== \App\Enums\UserStatus::ACTIVE) {
            Log::error('Organization is not active', [
                'call_id' => $callId,
                'organization_id' => $organizationId,
                'organization_status' => $organization->status->value,
            ]);

            return response(
                '<Response><Say>Service temporarily unavailable.</Say><Hangup/></Response>',
                200
            )->header('Content-Type', 'application/xml');
        }

        Log::info('Webhook validated for organization', [
            'call_id' => $callId,
            'organization_id' => $organizationId,
            'organization_name' => $organization->name,
            'to_number' => $toNumber,
            'direction' => $direction,
        ]);

        // Handle routing based on call type
        if ($direction === 'subscriber') {
            // Internal call - check if it's a direct extension dial
            $extension = \App\Models\Extension::where('organization_id', $organizationId)
                ->where('extension_number', $toNumber)
                ->where('status', 'active')
                ->first();

            if ($extension) {
                // Direct extension routing
                Log::info('Routing internal call to extension', [
                    'call_id' => $callId,
                    'extension_id' => $extension->id,
                    'extension_type' => $extension->type->value,
                ]);

                $cxml = $this->routeExtensionDirectly($extension, $request, $organizationId);
            } else {
                // No direct extension found, fall back to DID routing
                Log::info('No direct extension found, falling back to DID routing', [
                    'call_id' => $callId,
                    'to_number' => $toNumber,
                ]);

                $cxml = $this->routingService->routeInboundCall(
                    $toNumber,
                    $fromNumber,
                    $organizationId
                );
            }
        } else {
            // External call - use DID-based routing
            Log::info('Routing external call via DID', [
                'call_id' => $callId,
                'to_number' => $toNumber,
            ]);

            $cxml = $this->routingService->routeInboundCall(
                $toNumber,
                $fromNumber,
                $organizationId
            );
        }

        // Dispatch job to process webhook asynchronously (CDR creation)
        ProcessInboundCallJob::dispatch([
            'call_id' => $callId,
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'webhook_data' => $request->all(),
        ]);

        Log::info('Returning CXML response', [
            'call_id' => $callId,
            'cxml_length' => strlen($cxml),
        ]);

        return response($cxml, 200)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Route call directly to an extension based on its type
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string CXML response
     */
    private function routeExtensionDirectly(\App\Models\Extension $extension, $request, int $organizationId): string
    {
        return match ($extension->type) {
            \App\Enums\ExtensionType::USER => $this->routeUserExtensionDirectly($extension),
            \App\Enums\ExtensionType::CONFERENCE => $this->routeConferenceExtensionDirectly($extension, $organizationId),
            \App\Enums\ExtensionType::RING_GROUP => $this->routeRingGroupExtensionDirectly($extension, $organizationId),
            default => \App\Services\CxmlBuilder\CxmlBuilder::unavailable('Extension type not supported'),
        };
    }

    /**
     * Route directly to a user extension
     *
     * @return string CXML response
     */
    private function routeUserExtensionDirectly(\App\Models\Extension $extension): string
    {
        if (! $extension->user || ! $extension->user->isActive()) {
            return \App\Services\CxmlBuilder\CxmlBuilder::unavailable('Extension user not available');
        }

        $sipUri = $extension->getSipUri();
        if (! $sipUri) {
            return \App\Services\CxmlBuilder\CxmlBuilder::unavailable('Extension has no SIP configuration');
        }

        return \App\Services\CxmlBuilder\CxmlBuilder::simpleDial($sipUri);
    }

    /**
     * Route directly to a conference extension
     *
     * @return string CXML response
     */
    private function routeConferenceExtensionDirectly(\App\Models\Extension $extension, int $organizationId): string
    {
        $conferenceRoomId = $extension->configuration['conference_room_id'] ?? null;

        if (! $conferenceRoomId) {
            return \App\Services\CxmlBuilder\CxmlBuilder::unavailable('Conference room not configured');
        }

        $conferenceRoom = \App\Models\ConferenceRoom::where('id', $conferenceRoomId)
            ->where('organization_id', $organizationId)
            ->where('status', \App\Enums\UserStatus::ACTIVE)
            ->first();

        if (! $conferenceRoom) {
            return \App\Services\CxmlBuilder\CxmlBuilder::unavailable('Conference room not found');
        }

        $conferenceIdentifier = $this->generateConferenceIdentifier($conferenceRoom->name);

        return \App\Services\CxmlBuilder\CxmlBuilder::conference(
            $conferenceIdentifier,
            $conferenceRoom->pin
        );
    }

    /**
     * Route directly to a ring group extension
     *
     * @return string CXML response
     */
    private function routeRingGroupExtensionDirectly(\App\Models\Extension $extension, int $organizationId): string
    {
        $ringGroupId = $extension->configuration['ring_group_id'] ?? null;

        if (! $ringGroupId) {
            return \App\Services\CxmlBuilder\CxmlBuilder::unavailable('Ring group not configured');
        }

        $ringGroup = \App\Models\RingGroup::where('id', $ringGroupId)
            ->where('organization_id', $organizationId)
            ->where('status', \App\Enums\UserStatus::ACTIVE)
            ->first();

        if (! $ringGroup) {
            return \App\Services\CxmlBuilder\CxmlBuilder::unavailable('Ring group not found');
        }

        $members = $ringGroup->getMembers()->filter(fn (\App\Models\Extension $ext) => $ext->isActive());
        $sipUris = $members->map(fn (\App\Models\Extension $ext) => $ext->getSipUri())->filter()->values()->toArray();

        if (empty($sipUris)) {
            // Handle fallback
            $fallback = $ringGroup->fallback_action;

            return match ($fallback['action'] ?? 'hangup') {
                'extension' => $this->routeFallbackExtensionDirectly($fallback),
                'voicemail' => \App\Services\CxmlBuilder\CxmlBuilder::sendToVoicemail(),
                'busy' => \App\Services\CxmlBuilder\CxmlBuilder::busy($fallback['message'] ?? null),
                default => \App\Services\CxmlBuilder\CxmlBuilder::simpleHangup(),
            };
        }

        return \App\Services\CxmlBuilder\CxmlBuilder::dialRingGroup($sipUris, $ringGroup->timeout);
    }

    /**
     * Route to fallback extension directly
     *
     * @return string CXML response
     */
    private function routeFallbackExtensionDirectly(array $fallbackConfig): string
    {
        $extensionId = $fallbackConfig['extension_id'] ?? null;

        if (! $extensionId) {
            return \App\Services\CxmlBuilder\CxmlBuilder::simpleHangup();
        }

        $extension = \App\Models\Extension::find($extensionId);

        if (! $extension || ! $extension->isActive()) {
            return \App\Services\CxmlBuilder\CxmlBuilder::simpleHangup();
        }

        $sipUri = $extension->getSipUri();

        if (! $sipUri) {
            return \App\Services\CxmlBuilder\CxmlBuilder::simpleHangup();
        }

        return \App\Services\CxmlBuilder\CxmlBuilder::simpleDial($sipUri);
    }

    /**
     * Generate a clean conference identifier from conference room name
     */
    private function generateConferenceIdentifier(string $conferenceName): string
    {
        // Convert to lowercase and keep only letters, numbers, and spaces
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', '', strtolower($conferenceName));

        // Replace spaces with underscores and limit length
        $identifier = preg_replace('/\s+/', '_', trim($clean));

        // Ensure it's not empty and limit to 32 characters
        if (empty($identifier)) {
            $identifier = 'conference';
        }

        return substr($identifier, 0, 32);
    }

    /**
     * Handle session update webhook.
     * Session updates are sent during call progress for monitoring and debugging.
     */
    public function sessionUpdate(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $payload = $request->all();

        Log::info('Processing session-update webhook', [
            'request_id' => $requestId,
            'session_id' => $payload['id'] ?? null,
            'event_id' => $payload['eventId'] ?? null,
            'action' => $payload['action'] ?? null,
            'status' => $payload['status'] ?? null,
        ]);

        try {
            // Validate required fields
            $validated = $request->validate([
                'id' => 'required|integer',
                'eventId' => 'nullable|string',
                'domainId' => 'nullable|integer',
                'domain' => 'nullable|string',
                'subscriberId' => 'nullable|integer',
                'callerId' => 'nullable|string',
                'destination' => 'required|string',
                'direction' => 'nullable|in:incoming,outgoing,internal',
                'status' => 'nullable|string',
                'createdAt' => 'required|string',
                'modifiedAt' => 'required|string',
                'callStartTime' => 'nullable|integer',
                'callAnswerTime' => 'nullable|integer',
                'answerTime' => 'nullable|string',
                'timeLimit' => 'nullable|integer',
                'vappServer' => 'nullable|string',
                'action' => 'required|string',
                'reason' => 'nullable|string',
                'lastError' => 'nullable|string',
                'callIds' => 'nullable|array',
                'profile' => 'nullable|array',
            ]);

            // Filter events by status - only process specific statuses
            $allowedStatuses = ['processing', 'ringing', 'connected', 'answer'];
            if (! in_array($validated['status'], $allowedStatuses)) {
                Log::info('Session update ignored - status not in allowed list', [
                    'request_id' => $requestId,
                    'session_id' => $validated['id'],
                    'event_id' => $validated['eventId'],
                    'status' => $validated['status'],
                    'allowed_statuses' => $allowedStatuses,
                ]);

                // Return 200 OK to acknowledge receipt but indicate no processing
                return response()->json(['error' => 'Discarded Content'], 204);
            }

            // Identify organization from Cloudonix domain
            $organizationId = $this->identifyOrganizationFromDomain($validated['domain']);
            if (! $organizationId) {
                Log::error('Session update: Organization not identified from domain', [
                    'request_id' => $requestId,
                    'session_id' => $validated['id'],
                    'event_id' => $validated['eventId'],
                    'domain' => $validated['domain'],
                    'domain_id' => $validated['domainId'],
                ]);

                return response()->json(['error' => 'Organization not identified'], 403);
            }

            // Check for duplicate event (idempotency)
            $existingUpdate = SessionUpdate::where('organization_id', $organizationId)
                ->where('event_id', $validated['eventId'])
                ->first();

            if ($existingUpdate) {
                Log::info('Session update: Duplicate event ignored', [
                    'request_id' => $requestId,
                    'event_id' => $validated['eventId'],
                    'existing_id' => $existingUpdate->id,
                ]);

                return response()->json(['message' => 'Session record updated successfully'], 200);
            }

            // Process and store session update
            $sessionUpdate = new SessionUpdate([
                'organization_id' => $organizationId,
                'session_id' => $validated['id'],
                'event_id' => $validated['eventId'],
                'domain_id' => $validated['domainId'],
                'domain' => $validated['domain'],
                'subscriber_id' => $validated['subscriberId'],
                'outgoing_subscriber_id' => $validated['outgoingSubscriberId'] ?? null,
                'caller_id' => $this->normalizePhoneNumber($validated['callerId']),
                'destination' => $this->normalizePhoneNumber($validated['destination']),
                'direction' => $validated['direction'],
                'status' => $validated['status'],
                'session_created_at' => $validated['createdAt'],
                'session_modified_at' => $validated['modifiedAt'],
                'call_start_time' => $validated['callStartTime'] ?? null,
                'start_time' => isset($validated['startTime']) ? $validated['startTime'] : null,
                'call_answer_time' => $validated['callAnswerTime'] ?? null,
                'answer_time' => isset($validated['answerTime']) ? $validated['answerTime'] : null,
                'time_limit' => $validated['timeLimit'] ?? null,
                'vapp_server' => $validated['vappServer'] ?? null,
                'action' => $validated['action'],
                'reason' => $validated['reason'],
                'last_error' => $validated['lastError'] ?? null,
                'call_ids' => $validated['callIds'] ?? [],
                'profile' => $validated['profile'] ?? [],
            ]);

            $sessionUpdate->save();

            Log::info('Session update stored successfully', [
                'request_id' => $requestId,
                'session_update_id' => $sessionUpdate->id,
                'session_id' => $validated['id'],
                'event_id' => $validated['eventId'],
                'organization_id' => $organizationId,
            ]);

            return response()->json(['message' => 'Session record updated successfully'], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Session update validation failed', [
                'request_id' => $requestId,
                'errors' => $e->errors(),
                'payload' => $payload,
            ]);

            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 403);
        } catch (\Exception $e) {
            Log::error('Session update processing failed', [
                'request_id' => $requestId,
                'session_id' => $payload['id'] ?? null,
                'event_id' => $payload['eventId'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Session update processing failed',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * Handle CDR (Call Detail Record) webhook.
     * This is called by Cloudonix after a call completes.
     */
    public function cdr(CdrRequest $request): \Illuminate\Http\JsonResponse
    {
        $callId = $request->input('call_id');
        $organizationId = $request->input('_organization_id');

        Log::info('Received CDR webhook', [
            'call_id' => $callId,
            'organization_id' => $organizationId,
            'payload' => $request->all(),
        ]);

        // Validate organization was identified by middleware
        if (! $organizationId) {
            Log::warning('CDR webhook missing organization ID', [
                'call_id' => $callId,
            ]);

            throw new WebhookBusinessLogicException('Organization not identified');
        }

        // Process CDR synchronously so we can return proper response
        try {
            $cdr = \App\Models\CallDetailRecord::createFromWebhook($request->all(), $organizationId);

            Log::info('CDR created successfully', [
                'call_id' => $callId,
                'cdr_id' => $cdr->id,
                'organization_id' => $organizationId,
                'disposition' => $cdr->disposition,
                'duration' => $cdr->duration,
                'billsec' => $cdr->billsec,
            ]);

            // Also update CallLog if it exists (for backwards compatibility)
            $callLog = \App\Models\CallLog::where('call_id', $callId)->first();
            if ($callLog) {
                $updateData = [
                    'cloudonix_cdr' => $request->all(),
                ];

                if ($request->has('recording_url')) {
                    $updateData['recording_url'] = $request->input('recording_url');
                }

                if (! $callLog->duration && $request->has('duration')) {
                    $updateData['duration'] = (int) $request->input('duration');
                }

                $callLog->update($updateData);

                Log::info('CDR data also saved to call log', [
                    'call_id' => $callId,
                    'call_log_id' => $callLog->id,
                ]);
            }

            // Create session update record for final call status
            try {
                $this->createSessionUpdateFromCDR($request, $organizationId);

                Log::info('Session update created from CDR', [
                    'call_id' => $callId,
                    'organization_id' => $organizationId,
                    'disposition' => $request->input('disposition'),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to create session update from CDR', [
                    'call_id' => $callId,
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Don't fail the entire CDR processing for session update failure
                // Just log the error and continue
            }

            // Return 204 No Content to indicate successful creation
            return response()->json(['message' => 'CDR Inserted successfully'], 200);
        } catch (\Exception $e) {
            Log::error('CDR processing failed', [
                'call_id' => $callId,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Call Detail Record processing failed',
                'message' => 'Failed to process the call detail record. Please check the logs for more details.',
            ], 500);
        }
    }

    /**
     * Identify organization from Cloudonix domain information.
     */
    private function identifyOrganizationFromDomain(string $domain): ?int
    {
        // Find organization by domain name
        $settings = CloudonixSettings::where('domain_name', $domain)->first();

        return $settings?->organization_id;
    }

    /**
     * Create a session update record from CDR data to indicate final call status.
     */
    private function createSessionUpdateFromCDR(CdrRequest $request, int $organizationId): void
    {
        $sessionId = $request->input('session.id') ?? $request->input('call_id');
        $disposition = $request->input('disposition');

        // Use the disposition directly as the final status
        $status = $disposition;

        // Generate unique event ID for this final status update (use md5 for length constraint)
        $eventId = md5('cdr-final-'.$request->input('call_id').'-'.time());

        // Check for duplicate (idempotency) - don't create if final status already exists
        $existingFinalUpdate = SessionUpdate::where('organization_id', $organizationId)
            ->where('session_id', $sessionId)
            ->where('action', 'cdr_final_status')
            ->first();

        if ($existingFinalUpdate) {
            Log::info('CDR final status update already exists', [
                'call_id' => $request->input('call_id'),
                'session_id' => $sessionId,
                'existing_id' => $existingFinalUpdate->id,
            ]);

            return;
        }

        // Get session data
        $sessionData = $request->input('session', []);
        $callStartTimeMs = $sessionData['callStartTime'] ?? null;
        $callAnswerTimeMs = $sessionData['callAnswerTime'] ?? null;

        // Convert milliseconds to seconds for Carbon timestamps
        $callStartTimeSeconds = $callStartTimeMs ? intval($callStartTimeMs / 1000) : null;
        $callAnswerTimeSeconds = $callAnswerTimeMs ? intval($callAnswerTimeMs / 1000) : null;

        // Create session update record for final call status
        $sessionUpdate = new SessionUpdate([
            'organization_id' => $organizationId,
            'session_id' => $sessionId,
            'event_id' => $eventId,
            'domain_id' => $sessionData['domainId'] ?? null,
            'domain' => $request->input('domain'),
            'subscriber_id' => null, // Subscriber is UUID in CDR, not integer ID
            'caller_id' => $this->normalizePhoneNumber($request->input('from')),
            'destination' => $this->normalizePhoneNumber($request->input('to')),
            'direction' => 'incoming', // Assume incoming for CDR events
            'status' => $status,
            'session_created_at' => $callStartTimeSeconds
                ? \Carbon\Carbon::createFromTimestamp($callStartTimeSeconds)
                : now(),
            'session_modified_at' => now(),
            'call_start_time' => $callStartTimeMs,
            'call_answer_time' => $callAnswerTimeMs,
            'time_limit' => $sessionData['timeLimit'] ?? null,
            'vapp_server' => $request->input('vapp_server'),
            'action' => 'cdr_final_status',
            'reason' => $disposition, // Store original disposition
            'call_ids' => [$request->input('call_id')],
            'profile' => [
                'cdr_data' => $request->all(),
                'final_disposition' => $disposition,
                'duration' => $request->input('duration'),
                'billsec' => $request->input('billsec'),
            ],
        ]);

        $sessionUpdate->save();

        Log::info('Session update created from CDR', [
            'session_update_id' => $sessionUpdate->id,
            'call_id' => $request->input('call_id'),
            'session_id' => $sessionId,
            'disposition' => $disposition,
            'mapped_status' => $status,
        ]);
    }

    /**
     * Normalize phone number to E.164 format.
     */
    private function normalizePhoneNumber(?string $number): ?string
    {
        if (! $number) {
            return null;
        }

        // Remove common prefixes and formatting
        $number = preg_replace('/[^0-9+]/', '', $number);

        // Ensure + prefix for E.164
        if (! str_starts_with($number, '+')) {
            $number = '+'.$number;
        }

        return $number;
    }
}
