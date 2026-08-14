<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\Webhook\WebhookBusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesWebhookErrors;
use App\Http\Requests\Webhook\CallInitiatedRequest;
use App\Http\Requests\Webhook\CdrRequest;
use App\Http\Requests\Webhook\RecordingStatusRequest;
use App\Http\Requests\Webhook\SessionUpdateRequest;
use App\Jobs\DownloadCallRecordingJob;
use App\Jobs\ProcessInboundCallJob;
use App\Models\CallDetailRecord;
use App\Models\CallNotificationsSettings;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\SessionUpdate;
use App\Scopes\OrganizationScope;
use App\Services\AutoDialer\CDRPublisher;
use App\Services\CallNotifications\NotificationPayloadBuilder;
use App\Services\CallNotifications\WebhookDispatcher;
use App\Services\PhoneNumberService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Handles incoming webhooks from Cloudonix CPaaS.
 *
 * @see https://developers.cloudonix.com/Documentation/make.com/webhooks
 */
class CloudonixWebhookController extends Controller
{
    use HandlesWebhookErrors;

    public function __construct(
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly NotificationPayloadBuilder $payloadBuilder,
        private readonly PhoneNumberService $phoneNumberService,
        private readonly CDRPublisher $cdrPublisher
    ) {}

    /**
     * Handle inbound call initiated webhook (async notification only).
     *
     * This is an asynchronous notification from Cloudonix when a call arrives.
     * It does NOT return CXML — real-time call routing is handled by
     * VoiceRoutingController (/api/voice/route) which returns CXML.
     *
     * This handler:
     * 1. Logs the incoming call notification
     * 2. Dispatches a job to create a CallLog record
     * 3. Triggers real-time events for the frontend UI
     *
     * @see https://developers.cloudonix.com/Documentation/make.com/webhooks
     * @see VoiceRoutingController::handleInbound() for CXML routing
     */
    public function callInitiated(CallInitiatedRequest $request): \Illuminate\Http\JsonResponse
    {
        $callId = $request->input('CallSid') ?? $request->input('call_id');
        $from = $request->input('From') ?? $request->input('from');
        $to = $request->input('To') ?? $request->input('to');
        $direction = $request->input('Direction') ?? 'unknown';

        Log::info('Received call-initiated webhook (async notification)', [
            'call_id' => $callId,
            'from' => $from,
            'to' => $to,
            'direction' => $direction,
        ]);

        // Normalize phone numbers
        $fromNumber = $this->phoneNumberService->normalizeToE164($from);
        $toNumber = $this->phoneNumberService->normalizeToE164($to);

        // Identify organization from domain (set by VerifyCloudonixSignature middleware)
        // or fall back to DID-based lookup
        $organizationId = $request->input('_organization_id');

        if (! $organizationId) {
            // Try DID-based lookup
            $didNumber = DidNumber::where('phone_number', $toNumber)
                ->where('status', 'active')
                ->first();

            if ($didNumber) {
                $organizationId = $didNumber->organization_id;
            }
        }

        if (! $organizationId) {
            Log::warning('call-initiated: Could not identify organization', [
                'call_id' => $callId,
                'to_number' => $toNumber,
                'from' => $fromNumber,
                'direction' => $direction,
            ]);

            // Still return 200 to acknowledge receipt — don't retry
            return response()->json([
                'status' => 'warning',
                'message' => 'Organization not identified',
            ], 200);
        }

        // Dispatch async job to create call log and broadcast event
        ProcessInboundCallJob::dispatch([
            'call_id' => $callId,
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'webhook_data' => $request->all(),
        ]);

        Log::info('call-initiated: Dispatched async processing job', [
            'call_id' => $callId,
            'organization_id' => $organizationId,
        ]);

        return response()->json([
            'status' => 'accepted',
            'message' => 'Call notification received',
        ], 200);
    }

    /**
     * Handle call status webhook.
     * This is called by Cloudonix when a call's status changes.
     *
     * @see https://developers.cloudonix.com/Documentation/make.com/webhooks
     */
    public function callStatus(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $callId = $request->input('CallSid') ?? $request->input('call_id');
        $status = $request->input('CallStatus') ?? $request->input('status');

        Log::info('Received call-status webhook', [
            'call_id' => $callId,
            'status' => $status,
            'payload' => $request->all(),
        ]);

        // Update call log if it exists
        if ($callId) {
            $callLog = \App\Models\CallLog::where('call_id', $callId)->first();

            if ($callLog) {
                $updateData = [];

                if ($status) {
                    $updateData['status'] = $status;
                }

                if ($request->has('Duration') || $request->has('duration')) {
                    $updateData['duration'] = (int) ($request->input('Duration') ?? $request->input('duration'));
                }

                if (! empty($updateData)) {
                    $callLog->update($updateData);

                    Log::info('Call log updated from call-status webhook', [
                        'call_id' => $callId,
                        'call_log_id' => $callLog->id,
                        'updated_fields' => array_keys($updateData),
                    ]);
                }
            }
        }

        // Update auto-dialer session status if applicable
        $sessionToken = $request->input('token');
        if ($sessionToken && $status) {
            $statusMap = [
                'ringing' => 'ringing',
                'connected' => 'answered',
                'completed' => 'completed',
                'failed' => 'failed',
                'busy' => 'failed',
                'no-answer' => 'failed',
            ];
            $mappedStatus = $statusMap[strtolower($status)] ?? null;

            if ($mappedStatus) {
                \App\Scopes\OrganizationScope::bypass(function () use ($sessionToken, $mappedStatus) {
                    $session = \App\Models\AutoDialerCallSession::where('session_token', $sessionToken)->first();
                    if ($session && in_array($session->status, ['initiated', 'ringing'], true)) {
                        $session->update(['status' => $mappedStatus]);
                    }
                });
            }
        }

        return response()->json([
            'status' => 'accepted',
            'message' => 'Call status received',
        ], 200);
    }

    /**
     * Handle session update webhook.
     * Session updates are sent during call progress for monitoring and debugging.
     */
    public function sessionUpdate(SessionUpdateRequest $request): \Illuminate\Http\JsonResponse
    {
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $validated = $request->validated();

        Log::info('Processing session-update webhook', [
            'request_id' => $requestId,
            'session_id' => $validated['id'] ?? null,
            'event_id' => $validated['eventId'] ?? null,
            'action' => $validated['action'] ?? null,
            'status' => $validated['status'] ?? null,
        ]);

        try {

            // Filter events by status - only process specific statuses
            // These statuses map to notification events that users care about
            $allowedStatuses = [
                'new', 'initiated', 'created',           // Maps to 'new' event
                'ringing', 'ring', 'progress',           // Maps to 'ringing' event
                'connected', 'connect',                  // Maps to 'connected' event
                'answer', 'answered', 'active',          // Maps to 'answered' event
                'busy',                                  // Maps to 'busy' event
                'cancel', 'cancelled', 'canceled',       // Maps to 'cancel' event
                'failed', 'fail', 'error',               // Maps to 'failed' event
                'congestion', 'congested',               // Maps to 'congestion' event
            ];
            if (! in_array($validated['status'], $allowedStatuses)) {
                Log::info('Session update ignored - status not in allowed list', [
                    'request_id' => $requestId,
                    'session_id' => $validated['id'],
                    'event_id' => $validated['eventId'],
                    'status' => $validated['status'],
                    'allowed_statuses' => $allowedStatuses,
                ]);

                // Return 200 OK to acknowledge receipt but indicate no processing
                return response()->json(['message' => 'Status not processed'], 200);
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

            // Process and store session update within a transaction
            $sessionUpdate = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $organizationId, $requestId) {
                $sessionUpdate = SessionUpdate::create([
                    'organization_id' => $organizationId,
                    'session_id' => $validated['id'],
                    'session_token' => $validated['token'] ?? null,
                    'event_id' => $validated['eventId'],
                    'domain_id' => $validated['domainId'],
                    'domain' => $validated['domain'],
                    'subscriber_id' => $validated['subscriberId'],
                    'outgoing_subscriber_id' => $validated['outgoingSubscriberId'] ?? null,
                    'caller_id' => $this->phoneNumberService->normalizeToE164($validated['callerId']),
                    'destination' => $this->phoneNumberService->normalizeToE164($validated['destination']),
                    'direction' => $validated['direction'],
                    'status' => $validated['status'],
                    'session_created_at' => $validated['createdAt'],
                    'session_modified_at' => $validated['modifiedAt'],
                    'call_start_time' => $validated['callStartTime'] ?? null,
                    'start_time' => $validated['startTime'] ?? null,
                    'call_answer_time' => $validated['callAnswerTime'] ?? null,
                    'answer_time' => $validated['answerTime'] ?? null,
                    'time_limit' => $validated['timeLimit'] ?? null,
                    'vapp_server' => $validated['vappServer'] ?? null,
                    'action' => $validated['action'],
                    'reason' => $validated['reason'],
                    'last_error' => $validated['lastError'] ?? null,
                    'call_ids' => $validated['callIds'] ?? [],
                    'profile' => $validated['profile'] ?? [],
                ]);

                Log::info('Session update stored successfully', [
                    'request_id' => $requestId,
                    'session_update_id' => $sessionUpdate->id,
                    'session_id' => $validated['id'],
                    'event_id' => $validated['eventId'],
                    'organization_id' => $organizationId,
                ]);

                return $sessionUpdate;
            });

            // Trigger call notification webhook if enabled for this organization
            $this->triggerCallNotification($sessionUpdate, $organizationId);

            return response()->json(['message' => 'Session record updated successfully'], 200);
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
                'message' => 'An internal error occurred.',
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

        // Extract AMD result from session profile for logging
        $sessionProfile = $request->input('session.profile', []);
        $amdResult = $sessionProfile['amd']['result'] ?? null;
        $amdConfidence = $sessionProfile['amd']['confidence'] ?? null;

        Log::info('Received CDR webhook', [
            'call_id' => $callId,
            'organization_id' => $organizationId,
            'disposition' => $request->input('disposition'),
            'amd_result' => $amdResult,
            'amd_confidence' => $amdConfidence,
            'session_token' => $request->input('session.token') ?? $request->input('session_token'),
            'has_amd_in_profile' => isset($sessionProfile['amd']),
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
            $sessionUpdate = null;
            try {
                $sessionUpdate = $this->createSessionUpdateFromCDR($request, $organizationId);

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

            // Trigger call notification if session update was created
            if ($sessionUpdate) {
                $this->triggerCallNotification($sessionUpdate, $organizationId);
            }

            // Check if this is an auto-dialer call and update destination
            Log::info('CDR: Checking auto-dialer processing', [
                'call_id' => $callId,
                'session_token' => $request->input('session.token') ?? $request->input('session_token'),
            ]);
            try {
                $this->processAutoDialerCDR($request, $cdr);
                Log::info('CDR: Auto-dialer processing completed', [
                    'call_id' => $callId,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to process auto-dialer CDR', [
                    'call_id' => $callId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Don't fail the entire CDR processing
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
     * Handle Cloudonix's recordingStatusCallback webhook, fired once a
     * `<Dial record="...">` recording has finished processing.
     *
     * Per Cloudonix's docs (developers.cloudonix.com/Documentation/voiceApplication/Verb/dial),
     * this callback's parameters are CallSid, Session, RecordingSid, RecordingUrl,
     * RecordingStatus, RecordingDuration, RecordingStartTime - it carries no
     * `domain` field, unlike every other Cloudonix webhook. That means this
     * endpoint deliberately can't use the shared `webhook.signature` middleware
     * (which needs `domain` to resolve the organization). Instead, the
     * organization is derived from whichever CallDetailRecord the CallSid/Session
     * matches, and authenticated from there (see below). snake_case fallbacks
     * are also checked in case Cloudonix's real payload differs from the docs.
     */
    public function recordingStatus(RecordingStatusRequest $request): \Illuminate\Http\JsonResponse
    {
        $callId = $request->input('CallSid') ?? $request->input('call_id');
        $sessionToken = $request->input('Session') ?? $request->input('session_token') ?? $request->input('session.token');
        $recordingUrl = $request->input('RecordingUrl') ?? $request->input('recording_url');
        $recordingStatus = $request->input('RecordingStatus') ?? $request->input('recording_status');
        $recordingDuration = $request->input('RecordingDuration') ?? $request->input('recording_duration');

        Log::info('Received recording status webhook', [
            'call_id' => $callId,
            'session_token' => $sessionToken,
            'recording_status' => $recordingStatus,
            'recording_url' => $recordingUrl,
            'recording_duration' => $recordingDuration,
            'payload' => $request->all(),
        ]);

        // 'absent' means Cloudonix determined there is no recording to fetch;
        // 'in-progress' only arrives if recordingStatusCallbackEvent is
        // explicitly widened beyond the 'completed' default this app requests -
        // neither carries a usable RecordingUrl.
        if ($recordingStatus === 'absent' || ! $recordingUrl) {
            Log::info('Recording status webhook: nothing to download', [
                'call_id' => $callId,
                'recording_status' => $recordingStatus,
            ]);

            $query = CallDetailRecord::withoutGlobalScope(OrganizationScope::class);
            $cdr = $callId ? $query->where('call_id', $callId)->first() : null;
            $cdr?->update(['recording_status' => 'failed']);

            return response()->json(['message' => 'No recording to download'], 200);
        }

        $query = CallDetailRecord::withoutGlobalScope(OrganizationScope::class);

        $cdr = $callId
            ? $query->where('call_id', $callId)->first()
            : null;

        if (! $cdr && $sessionToken) {
            $cdr = CallDetailRecord::withoutGlobalScope(OrganizationScope::class)
                ->where('session_token', $sessionToken)
                ->first();
        }

        if (! $cdr) {
            Log::warning('Recording status webhook: no matching CDR found', [
                'call_id' => $callId,
                'session_token' => $sessionToken,
            ]);

            return response()->json(['message' => 'No matching call detail record found'], 200);
        }

        // This payload carries no `domain` field, so the shared webhook.signature
        // middleware can't run for this route (see routes/webhooks.php). Once the
        // organization is known via the matched CDR, apply the same bearer-token
        // check that middleware performs for every other Cloudonix webhook.
        $settings = CloudonixSettings::where('organization_id', $cdr->organization_id)->first();
        if ($settings && $settings->domain_requests_api_key) {
            $token = $request->bearerToken();
            if (! $token || ! hash_equals($settings->domain_requests_api_key, $token)) {
                Log::warning('Recording status webhook: authentication failed', [
                    'organization_id' => $cdr->organization_id,
                    'call_id' => $callId,
                ]);

                return response()->json(['error' => 'Unauthorized'], 401);
            }
        }

        $cdr->update([
            'recording_status' => 'pending',
            'recording_source_url' => $recordingUrl,
            'recording_duration' => $recordingDuration !== null ? (int) $recordingDuration : null,
        ]);

        DownloadCallRecordingJob::dispatch($cdr->id, $recordingUrl);

        Log::info('Recording download job dispatched', [
            'call_detail_record_id' => $cdr->id,
            'call_id' => $callId,
        ]);

        return response()->json(['message' => 'Recording status processed'], 200);
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
     * Returns the created SessionUpdate model or null if duplicate.
     */
    private function createSessionUpdateFromCDR(CdrRequest $request, int $organizationId): ?SessionUpdate
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

            return null;
        }

        // Get session data
        $sessionData = $request->input('session', []);
        $callStartTimeMs = $sessionData['callStartTime'] ?? null;
        $callAnswerTimeMs = $sessionData['callAnswerTime'] ?? null;

        // Convert milliseconds to seconds for Carbon timestamps
        $callStartTimeSeconds = $callStartTimeMs ? intval($callStartTimeMs / 1000) : null;
        $callAnswerTimeSeconds = $callAnswerTimeMs ? intval($callAnswerTimeMs / 1000) : null;

        // Extract session token from session.token field
        $sessionToken = $sessionData['token'] ?? null;

        // Create session update record for final call status
        $sessionUpdate = new SessionUpdate([
            'organization_id' => $organizationId,
            'session_id' => $sessionId,
            'session_token' => $sessionToken,
            'event_id' => $eventId,
            'domain_id' => $sessionData['domainId'] ?? null,
            'domain' => $request->input('domain'),
            'subscriber_id' => null, // Subscriber is UUID in CDR, not integer ID
            'caller_id' => $this->phoneNumberService->normalizeToE164($request->input('from')),
            'destination' => $this->phoneNumberService->normalizeToE164($request->input('to')),
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

        return $sessionUpdate;
    }

    /**
     * Trigger call notification webhook for the session update.
     */
    private function triggerCallNotification(SessionUpdate $sessionUpdate, int $organizationId): void
    {
        Log::info('=== CALL NOTIFICATION START ===', [
            'organization_id' => $organizationId,
            'session_id' => $sessionUpdate->session_id,
            'status' => $sessionUpdate->status,
            'action' => $sessionUpdate->action,
        ]);

        try {
            // Get notification settings for organization
            // Must bypass OrganizationScope since webhooks have no authenticated user
            Log::info('NOTIFICATION: Looking up settings...', [
                'organization_id' => $organizationId,
            ]);

            $settings = \App\Scopes\OrganizationScope::bypass(function () use ($organizationId) {
                return CallNotificationsSettings::forOrganization($organizationId)
                    ->active()
                    ->first();
            });

            if (! $settings) {
                Log::info('NOTIFICATION: No settings found for organization', [
                    'organization_id' => $organizationId,
                ]);

                return;
            }

            Log::info('NOTIFICATION: Settings found', [
                'organization_id' => $organizationId,
                'settings_id' => $settings->id,
                'webhook_url' => $settings->webhook_url,
                'is_active' => $settings->is_active,
            ]);

            if (! $settings->isConfigured()) {
                Log::info('NOTIFICATION: Settings not configured', [
                    'organization_id' => $organizationId,
                    'webhook_url' => $settings->webhook_url,
                    'is_active' => $settings->is_active,
                ]);

                return;
            }

            Log::info('NOTIFICATION: Settings are configured', [
                'organization_id' => $organizationId,
                'webhook_url' => $settings->webhook_url,
                'enabled_events' => $settings->enabled_events,
            ]);

            // Check if this status should trigger a notification
            $normalizedStatus = $this->normalizeNotificationStatus($sessionUpdate->status ?? 'unknown');

            Log::info('NOTIFICATION: Status normalized', [
                'original_status' => $sessionUpdate->status,
                'normalized_status' => $normalizedStatus,
            ]);

            if (! $settings->isEventEnabled($normalizedStatus)) {
                Log::info('NOTIFICATION: Event not enabled', [
                    'organization_id' => $organizationId,
                    'session_id' => $sessionUpdate->session_id,
                    'status' => $normalizedStatus,
                    'enabled_events' => $settings->enabled_events,
                ]);

                return;
            }

            Log::info('NOTIFICATION: Event is enabled, proceeding...');

            // Get previous status from database
            // Must bypass OrganizationScope since webhooks have no authenticated user
            $previousStatus = \App\Scopes\OrganizationScope::bypass(function () use ($organizationId, $sessionUpdate) {
                return SessionUpdate::where('organization_id', $organizationId)
                    ->where('session_id', $sessionUpdate->session_id)
                    ->where('id', '<', $sessionUpdate->id)
                    ->orderBy('id', 'desc')
                    ->value('status') ?? 'unknown';
            });

            Log::info('NOTIFICATION: Previous status retrieved', [
                'previous_status' => $previousStatus,
            ]);

            // Build notification payload
            Log::info('NOTIFICATION: Building payload...');
            $payload = $this->payloadBuilder->build($sessionUpdate, $previousStatus);
            Log::info('NOTIFICATION: Payload built', [
                'event_id' => $payload['event_id'] ?? 'missing',
            ]);

            // Dispatch webhook
            Log::info('NOTIFICATION: Dispatching webhook...', [
                'webhook_url' => $settings->webhook_url,
            ]);

            $result = $this->webhookDispatcher->dispatch(
                $settings,
                $payload,
                $payload['event_id'],
                $sessionUpdate->session_token ?? (string) $sessionUpdate->session_id
            );

            Log::info('=== CALL NOTIFICATION END ===', [
                'organization_id' => $organizationId,
                'session_id' => $sessionUpdate->session_id,
                'dispatch_result' => $result ? 'success' : 'failed',
            ]);

        } catch (\Exception $e) {
            Log::error('=== CALL NOTIFICATION EXCEPTION ===', [
                'organization_id' => $organizationId,
                'session_id' => $sessionUpdate->session_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Normalize status for notification event checking.
     */
    private function normalizeNotificationStatus(string $status): string
    {
        $statusMap = [
            'new' => 'new',
            'initiated' => 'new',
            'created' => 'new',
            'ringing' => 'ringing',
            'ring' => 'ringing',
            'progress' => 'ringing',
            'connected' => 'connected',
            'connect' => 'connected',
            'answer' => 'answered',
            'answered' => 'answered',
            'active' => 'answered',
            'busy' => 'busy',
            'cancel' => 'cancel',
            'cancelled' => 'cancel',
            'canceled' => 'cancel',
            'failed' => 'failed',
            'fail' => 'failed',
            'error' => 'failed',
            'congestion' => 'congestion',
            'congested' => 'congestion',
        ];

        return $statusMap[strtolower($status)] ?? strtolower($status);
    }

    /**
     * Process CDR for auto-dialer calls.
     *
     * Updates auto-dialer destination records when a CDR is received.
     */
    private function processAutoDialerCDR(\Illuminate\Http\Request $request, \App\Models\CallDetailRecord $cdr): void
    {
        // Cloudonix CDR nests the session token at session.token, not session_token
        $sessionToken = $request->input('session.token') ?? $request->input('session_token');
        $callId = $request->input('call_id');

        // Log entry into processAutoDialerCDR
        $sessionProfile = $request->input('session.profile', []);
        $amdResult = $sessionProfile['amd']['result'] ?? null;
        Log::info('processAutoDialerCDR: ENTRY', [
            'call_id' => $callId,
            'session_token' => $sessionToken,
            'amd_result' => $amdResult,
            'session_profile' => $sessionProfile,
        ]);

        if (! $sessionToken) {
            Log::debug('Auto-dialer CDR skipped: no session token found', [
                'call_id' => $callId,
                'has_session_object' => $request->has('session'),
            ]);

            return;
        }

        // All auto-dialer queries must bypass OrganizationScope since
        // webhooks have no authenticated user context.
        \App\Scopes\OrganizationScope::bypass(function () use ($request, $cdr, $sessionToken, $callId): void {
            // Find auto-dialer session by session token
            $session = \App\Models\AutoDialerCallSession::where('session_token', $sessionToken)->first();

            if (! $session) {
                // Not an auto-dialer call — this is normal for regular inbound/outbound calls
                return;
            }

            // Check for AMD result in session profile from CDR payload
            // NOTE: Due to race conditions, the CDR may not include the updated
            // profile if the call ended immediately after AMD detection. Fall back
            // to the locally stored AMD result from the AmdActionController.
            $sessionProfile = $request->input('session.profile', []);
            $cdrAmdResult = $sessionProfile['amd']['result'] ?? null;

            // Use locally stored AMD result if CDR doesn't have it
            $amdResult = $cdrAmdResult ?? $session->amd_result;

            Log::info('Auto-dialer: AMD result resolution', [
                'session_token' => $sessionToken,
                'cdr_amd_result' => $cdrAmdResult,
                'local_amd_result' => $session->amd_result,
                'resolved_amd_result' => $amdResult,
            ]);

            // Load campaign for retry logic
            $campaign = \App\Models\AutoDialerCampaign::find($session->campaign_id);

            // Update the session itself with CDR data
            $disposition = $request->input('disposition', 'unknown');
            $isCompleted = in_array(strtoupper($disposition), ['ANSWER', 'ANSWERED', 'COMPLETED'], true);
            $session->update([
                'status' => $isCompleted ? 'completed' : 'failed',
                'disposition' => $disposition,
                'duration' => (int) $request->input('duration', 0),
                'billsec' => (int) $request->input('billsec', 0),
                'completed_at' => now(),
            ]);

            // Store AMD result on session if present (from CDR or already stored locally)
            if ($amdResult) {
                $session->update([
                    'amd_result' => $amdResult,
                    'amd_confidence' => $sessionProfile['amd']['confidence'] ?? $session->amd_confidence,
                ]);
                Log::info('Auto-dialer: AMD result stored on session', [
                    'session_id' => $session->id,
                    'amd_result' => $amdResult,
                ]);
            }

            // Update destination record
            $destination = \App\Models\AutoDialerDestination::find($session->destination_id);
            $shouldRetryVoicemail = false;

            if ($destination) {
                // Check for voicemail retry
                $shouldRetryVoicemail = ($amdResult === 'voicemail')
                    && $campaign
                    && $campaign->retry_on_voicemail
                    && ($destination->dial_attempts < $campaign->max_dial_attempts);

                if ($shouldRetryVoicemail) {
                    // Schedule retry for voicemail
                    $nextRetry = now()->addMinutes(5 * (2 ** ($destination->dial_attempts - 1)));
                    if ($nextRetry->diffInMinutes(now()) > 60) {
                        $nextRetry = now()->addMinutes(60);
                    }

                    $destination->update([
                        'status' => \App\Enums\DestinationStatus::PENDING,
                        'next_retry_at' => $nextRetry,
                        'last_disposition' => 'voicemail',
                        'duration' => $request->input('duration', 0),
                        'billsec' => $request->input('billsec', 0),
                        'last_cdr_id' => $cdr->id,
                    ]);

                    Log::info('Auto-dialer destination scheduled for voicemail retry', [
                        'destination_id' => $destination->id,
                        'attempts' => $destination->dial_attempts,
                        'next_retry_at' => $nextRetry,
                    ]);
                } else {
                    $destination->update([
                        'status' => $this->mapDispositionToStatus($request->input('disposition')),
                        'last_disposition' => $request->input('disposition'),
                        'duration' => $request->input('duration', 0),
                        'billsec' => $request->input('billsec', 0),
                        'last_cdr_id' => $cdr->id,
                    ]);
                }

                Log::info('Auto-dialer destination updated', [
                    'call_id' => $callId,
                    'session_token' => $sessionToken,
                    'destination_id' => $destination->id,
                    'campaign_id' => $session->campaign_id,
                    'amd_result' => $amdResult,
                ]);
            }

            // Update CDR with AMD data and auto-dialer linkage
            // If Cloudonix CDR payload is missing AMD profile, inject it from
            // the local session record so the Call Log shows the detection result.
            $rawCdr = $cdr->raw_cdr ?? [];
            $sessionProfile = $rawCdr['session']['profile'] ?? [];
            $cdrHasAmd = isset($sessionProfile['amd']['result']);

            if (! $cdrHasAmd && $amdResult) {
                // Inject AMD data into raw_cdr JSON so frontend Call Log can display it
                $rawCdr['session']['profile']['amd'] = [
                    'result' => $amdResult,
                    'confidence' => $session->amd_confidence,
                    'timestamp' => $session->updated_at?->toIso8601String(),
                ];
                Log::info('Auto-dialer: Injected AMD data into CDR raw payload', [
                    'call_id' => $callId,
                    'session_token' => $sessionToken,
                    'amd_result' => $amdResult,
                ]);
            }

            $cdr->update([
                'is_auto_dialer' => true,
                'auto_dialer_campaign_id' => $session->campaign_id,
                'amd_result' => $amdResult,
                'amd_confidence' => $session->amd_confidence,
                'raw_cdr' => $rawCdr,
            ]);

            Log::info('Auto-dialer CDR processed', [
                'call_id' => $callId,
                'session_token' => $sessionToken,
                'campaign_id' => $session->campaign_id,
                'destination_id' => $destination ? $destination->id : null,
                'amd_result' => $amdResult,
                'should_retry_voicemail' => $shouldRetryVoicemail,
            ]);

            // Update campaign statistics
            Log::info('Auto-dialer: Starting campaign stats update', [
                'session_token' => $sessionToken,
                'campaign_id' => $session->campaign_id,
                'amd_result' => $amdResult,
                'should_retry_voicemail' => $shouldRetryVoicemail,
                'disposition' => $disposition,
            ]);

            $campaign = \App\Models\AutoDialerCampaign::find($session->campaign_id);
            if ($campaign) {
                Log::info('Auto-dialer: Campaign found, current stats', [
                    'campaign_id' => $campaign->id,
                    'voicemail_calls' => $campaign->voicemail_calls,
                    'completed_calls' => $campaign->completed_calls,
                    'pending_calls' => $campaign->pending_calls,
                ]);

                if ($amdResult === 'voicemail' && $shouldRetryVoicemail) {
                    // Voicemail with retry: don't count as completed, just decrement pending
                    $campaign->decrement('pending_calls');
                    Log::info('Auto-dialer campaign stats: voicemail retry', [
                        'campaign_id' => $campaign->id,
                        'action' => 'decrement_pending',
                    ]);
                } else {
                    $campaign->increment('completed_calls');
                    $campaign->decrement('pending_calls');
                    Log::info('Auto-dialer campaign stats: call completed', [
                        'campaign_id' => $campaign->id,
                        'action' => 'increment_completed_decrement_pending',
                    ]);
                }

                // Increment voicemail counter if AMD detected voicemail
                Log::info('Auto-dialer: Checking voicemail counter condition', [
                    'campaign_id' => $campaign->id,
                    'amd_result' => $amdResult,
                    'is_voicemail' => $amdResult === 'voicemail',
                    'will_increment' => $amdResult === 'voicemail',
                ]);

                if ($amdResult === 'voicemail') {
                    $voicemailBefore = $campaign->voicemail_calls;
                    $campaign->increment('voicemail_calls');
                    $campaign->refresh(); // Get fresh data after increment
                    Log::info('Auto-dialer campaign stats: voicemail detected - COUNTER INCREMENTED', [
                        'campaign_id' => $campaign->id,
                        'voicemail_calls_before' => $voicemailBefore,
                        'voicemail_calls_after' => $campaign->voicemail_calls,
                        'action' => 'increment_voicemail',
                    ]);
                } else {
                    Log::info('Auto-dialer: Not a voicemail, skipping counter increment', [
                        'campaign_id' => $campaign->id,
                        'amd_result' => $amdResult,
                    ]);
                }
            } else {
                Log::warning('Auto-dialer campaign not found for stats update', [
                    'campaign_id' => $session->campaign_id,
                    'session_token' => $sessionToken,
                ]);
            }

            // Decrement the CAC counter directly in Redis.
            // The Go worker increments this when initiating calls but has no
            // Pub/Sub subscriber to decrement it — Laravel handles this directly.
            $this->decrementCacCounter($session->campaign_id, $session->session_token);

            // Also publish CDR event to Redis channel for any future subscribers
            $this->cdrPublisher->publish(
                sessionToken: $sessionToken,
                campaignId: $session->campaign_id,
                destinationId: $destination ? $destination->id : $session->destination_id,
                sessionId: $session->id,
                disposition: $request->input('disposition', 'unknown'),
                duration: (int) $request->input('duration', 0),
                billsec: (int) $request->input('billsec', 0)
            );
        });
    }

    /**
     * Map CDR disposition to destination status.
     */
    private function mapDispositionToStatus(?string $disposition): string
    {
        $dispositionMap = [
            'answered' => 'completed',
            'completed' => 'completed',
            'busy' => 'failed',
            'no-answer' => 'failed',
            'failed' => 'failed',
            'cancelled' => 'failed',
            'congestion' => 'failed',
        ];

        return $dispositionMap[strtolower($disposition ?? '')] ?? 'completed';
    }

    /**
     * Decrement the CAC (Concurrent Active Calls) counter in Redis.
     *
     * The Go dialer worker increments this counter when initiating a call.
     * Laravel decrements it directly when processing CDR webhooks, since the
     * worker has no Pub/Sub subscriber for the cdr:completed channel.
     * Idempotency is enforced per session token so a completed call only
     * decrements the counter once, even if multiple webhooks arrive.
     *
     * Uses the 'dialer' connection (no key prefix) because the Go worker
     * writes raw keys like dialer:cac:{id}:active without any prefix.
     * The default Laravel Redis connection adds a prefix, which would
     * cause Laravel and the Go worker to read/write different keys.
     */
    private function decrementCacCounter(int $campaignId, string $sessionToken): void
    {
        try {
            $redis = Redis::connection('dialer');

            $idempotencyKey = "dialer:cac:decremented:{$sessionToken}";
            if ($redis->get($idempotencyKey)) {
                Log::info('CAC decrement already processed for session', [
                    'campaign_id' => $campaignId,
                    'session_token' => $sessionToken,
                ]);

                return;
            }

            $workerKey = "dialer:cac:{$campaignId}:active";
            $newCount = $redis->decr($workerKey);

            // Prevent counter from going negative
            if ($newCount < 0) {
                $redis->set($workerKey, 0);
                $newCount = 0;
            }

            $redis->setex($idempotencyKey, 86400, '1');

            Log::info('CAC counter decremented', [
                'campaign_id' => $campaignId,
                'active_calls' => $newCount,
                'session_token' => $sessionToken,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to decrement CAC counter', [
                'campaign_id' => $campaignId,
                'session_token' => $sessionToken,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
