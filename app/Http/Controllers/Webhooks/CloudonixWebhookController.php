<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhook\CallInitiatedRequest;
use App\Http\Requests\Webhook\CallStatusRequest;
use App\Http\Requests\Webhook\CdrRequest;
use App\Jobs\ProcessCDRJob;
use App\Jobs\ProcessInboundCallJob;
use App\Jobs\UpdateCallStatusJob;
use App\Models\DidNumber;
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
     * Handle call status update webhook.
     * This is called by Cloudonix when call status changes.
     */
    public function callStatus(CallStatusRequest $request): Response
    {
        $callId = $request->input('CallSid') ?? $request->input('call_id');
        $status = $request->input('CallStatus') ?? $request->input('status');

        Log::info('Received call-status webhook', [
            'call_id' => $callId,
            'status' => $status,
            'payload' => $request->all(),
        ]);

        // Dispatch job to process status update asynchronously
        UpdateCallStatusJob::dispatch([
            'call_id' => $callId,
            'status' => $status,
            'webhook_data' => $request->all(),
        ]);

        return response('', 200);
    }

    /**
     * Handle CDR (Call Detail Record) webhook.
     * This is called by Cloudonix after a call completes.
     */
    public function cdr(CdrRequest $request): Response
    {
        $callId = $request->input('call_id');
        $organizationId = $request->input('_organization_id');

        Log::info('Received CDR webhook', [
            'call_id' => $callId,
            'organization_id' => $organizationId,
            'payload' => $request->all(),
        ]);

        // Dispatch job to process CDR asynchronously
        ProcessCDRJob::dispatch($request->all());

        return response('', 200);
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
