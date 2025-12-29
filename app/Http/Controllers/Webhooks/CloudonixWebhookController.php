<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\Webhook\WebhookBusinessLogicException;
use App\Exceptions\Webhook\WebhookTransientException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesWebhookErrors;
use App\Http\Requests\Webhook\CallInitiatedRequest;
use App\Http\Requests\Webhook\CallStatusRequest;
use App\Http\Requests\Webhook\CdrRequest;
use App\Jobs\ProcessCDRJob;
use App\Jobs\ProcessInboundCallJob;
use App\Jobs\UpdateCallStatusJob;
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
    ) {
    }

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

        // Find organization by DID (with eager loading)
        $didNumber = DidNumber::with('organization:id,name,status')
            ->where('phone_number', $toNumber)
            ->where('status', 'active')
            ->first();

        if (!$didNumber) {
            Log::warning('DID not found for webhook', [
                'call_id' => $callId,
                'to_number' => $toNumber,
                'from' => $fromNumber,
            ]);

            return response(
                '<Response><Say>This number is not configured.</Say><Hangup/></Response>',
                200
            )->header('Content-Type', 'application/xml');
        }

        // Validate organization exists and is active
        if (!$didNumber->organization) {
            Log::error('DID belongs to non-existent organization', [
                'call_id' => $callId,
                'did_id' => $didNumber->id,
                'phone_number' => $toNumber,
                'organization_id' => $didNumber->organization_id,
            ]);

            return response(
                '<Response><Say>Service temporarily unavailable.</Say><Hangup/></Response>',
                200
            )->header('Content-Type', 'application/xml');
        }

        if ($didNumber->organization->status !== 'active') {
            Log::warning('Call to inactive organization', [
                'call_id' => $callId,
                'did_id' => $didNumber->id,
                'organization_id' => $didNumber->organization_id,
                'organization_status' => $didNumber->organization->status,
            ]);

            return response(
                '<Response><Say>Service temporarily unavailable.</Say><Hangup/></Response>',
                200
            )->header('Content-Type', 'application/xml');
        }

        Log::info('Webhook validated for organization', [
            'call_id' => $callId,
            'organization_id' => $didNumber->organization_id,
            'organization_name' => $didNumber->organization->name,
            'did_number' => $toNumber,
        ]);

        // Dispatch job to process webhook asynchronously
        ProcessInboundCallJob::dispatch([
            'call_id' => $callId,
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'webhook_data' => $request->all(),
        ]);

        // Generate and return CXML routing decision
        $cxml = $this->routingService->routeInboundCall(
            $toNumber,
            $fromNumber,
            $didNumber->organization_id
        );

        Log::info('Returning CXML response', [
            'call_id' => $callId,
            'cxml_length' => strlen($cxml),
        ]);

        return response($cxml, 200)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Handle session update webhook.
     * Session updates are sent during call progress for monitoring and debugging.
     */
    public function sessionUpdate(\Illuminate\Http\Request $request): Response
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
                'eventId' => 'required|string',
                'domainId' => 'required|integer',
                'domain' => 'required|string',
                'subscriberId' => 'required|integer',
                'callerId' => 'required|string',
                'destination' => 'required|string',
                'direction' => 'required|in:incoming,outgoing',
                'status' => 'required|string',
                'createdAt' => 'required|string',
                'modifiedAt' => 'required|string',
                'action' => 'required|string',
                'reason' => 'required|string',
                'callIds' => 'required|array',
                'profile' => 'required|array',
            ]);

            // Identify organization from Cloudonix domain
            $organizationId = $this->identifyOrganizationFromDomain($validated['domain']);
            if (!$organizationId) {
                Log::error('Session update: Organization not identified from domain', [
                    'request_id' => $requestId,
                    'session_id' => $validated['id'],
                    'event_id' => $validated['eventId'],
                    'domain' => $validated['domain'],
                    'domain_id' => $validated['domainId'],
                ]);
                return response()->json(['error' => 'Organization not identified'], 400);
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
            return response('', 201); // HTTP 201 Created - resource successfully created
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
                'call_ids' => $validated['callIds'],
                'profile' => $validated['profile'],
            ]);

            $sessionUpdate->save();

            Log::info('Session update stored successfully', [
                'request_id' => $requestId,
                'session_update_id' => $sessionUpdate->id,
                'session_id' => $validated['id'],
                'event_id' => $validated['eventId'],
                'organization_id' => $organizationId,
            ]);

            return response('', 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Session update validation failed', [
                'request_id' => $requestId,
                'errors' => $e->errors(),
                'payload' => $payload,
            ]);
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 400);

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
                'message' => $e->getMessage()
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
        if (!$organizationId) {
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

                if (!$callLog->duration && $request->has('duration')) {
                    $updateData['duration'] = (int) $request->input('duration');
                }

                $callLog->update($updateData);

                Log::info('CDR data also saved to call log', [
                    'call_id' => $callId,
                    'call_log_id' => $callLog->id,
                ]);
            }

            // Return 201 No Content to indicate successful creation
            return response('', 201);

        } catch (\Exception $e) {
            Log::error('CDR processing failed', [
                'call_id' => $callId,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Call Detail Record processing failed',
                'message' => 'Failed to process the call detail record. Please check the logs for more details.'
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
     * Normalize phone number to E.164 format.
     */
    private function normalizePhoneNumber(?string $number): ?string
    {
        if (!$number) {
            return null;
        }

        // Remove common prefixes and formatting
        $number = preg_replace('/[^0-9+]/', '', $number);

        // Ensure + prefix for E.164
        if (!str_starts_with($number, '+')) {
            $number = '+' . $number;
        }

        return $number;
    }
}
