<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\Webhook\WebhookBusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesWebhookErrors;
use App\Http\Requests\Webhook\CallInitiatedRequest;
use App\Http\Requests\Webhook\CdrRequest;
use App\Http\Requests\Webhook\SessionUpdateRequest;
use App\Jobs\ProcessInboundCallJob;
use App\Models\CallNotificationsSettings;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\SessionUpdate;
use App\Services\CallNotifications\NotificationPayloadBuilder;
use App\Services\CallNotifications\WebhookDispatcher;
use App\Services\PhoneNumberService;
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
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly NotificationPayloadBuilder $payloadBuilder,
        private readonly PhoneNumberService $phoneNumberService
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

            // Check if this is an auto-dialer call and update destination
            try {
                $this->processAutoDialerCDR($request, $cdr);
            } catch (\Exception $e) {
                Log::error('Failed to process auto-dialer CDR', [
                    'call_id' => $callId,
                    'error' => $e->getMessage(),
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
    }

    /**
     * Trigger call notification webhook for the session update.
     */
    private function triggerCallNotification(SessionUpdate $sessionUpdate, int $organizationId): void
    {
        try {
            // Get notification settings for organization
            $settings = CallNotificationsSettings::forOrganization($organizationId)
                ->active()
                ->first();

            if (! $settings || ! $settings->isConfigured()) {
                // No notification settings configured
                return;
            }

            // Check if this status should trigger a notification
            $normalizedStatus = $this->normalizeNotificationStatus($sessionUpdate->status ?? 'unknown');
            if (! $settings->isEventEnabled($normalizedStatus)) {
                Log::debug('Call notification skipped - event not enabled', [
                    'organization_id' => $organizationId,
                    'session_id' => $sessionUpdate->session_id,
                    'status' => $normalizedStatus,
                    'enabled_events' => $settings->enabled_events,
                ]);

                return;
            }

            // Get previous status from database
            $previousStatus = SessionUpdate::where('organization_id', $organizationId)
                ->where('session_id', $sessionUpdate->session_id)
                ->where('id', '<', $sessionUpdate->id)
                ->orderBy('id', 'desc')
                ->value('status') ?? 'unknown';

            // Build notification payload
            $payload = $this->payloadBuilder->build($sessionUpdate, $previousStatus);

            // Dispatch webhook
            $this->webhookDispatcher->dispatch(
                $settings,
                $payload,
                $payload['event_id'],
                $sessionUpdate->session_token ?? (string) $sessionUpdate->session_id
            );

            Log::info('Call notification triggered', [
                'organization_id' => $organizationId,
                'session_id' => $sessionUpdate->session_id,
                'event_id' => $payload['event_id'],
                'status' => $normalizedStatus,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to trigger call notification', [
                'organization_id' => $organizationId,
                'session_id' => $sessionUpdate->session_id,
                'error' => $e->getMessage(),
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
        $sessionToken = $request->input('session_token');
        $callId = $request->input('call_id');

        if (! $sessionToken) {
            return;
        }

        // Find auto-dialer session by session token
        $session = \App\Models\AutoDialerCallSession::where('session_token', $sessionToken)->first();

        if (! $session) {
            return;
        }

        // Update destination record
        $destination = \App\Models\AutoDialerDestination::find($session->destination_id);

        if ($destination) {
            $destination->update([
                'status' => $this->mapDispositionToStatus($request->input('disposition')),
                'last_disposition' => $request->input('disposition'),
                'duration' => $request->input('duration', 0),
                'billsec' => $request->input('billsec', 0),
                'last_cdr_id' => $cdr->id,
            ]);

            // Increment dial attempts
            $destination->incrementDialAttempts();

            // Update CDR to mark as auto-dialer call
            $cdr->update([
                'is_auto_dialer' => true,
                'auto_dialer_campaign_id' => $session->campaign_id,
            ]);

            Log::info('Auto-dialer CDR processed', [
                'call_id' => $callId,
                'session_token' => $sessionToken,
                'destination_id' => $destination->id,
                'campaign_id' => $session->campaign_id,
            ]);
        }

        // Update campaign statistics
        $campaign = \App\Models\AutoDialerCampaign::find($session->campaign_id);
        if ($campaign) {
            $campaign->increment('completed_calls');
            $campaign->decrement('pending_calls');
        }
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
}
