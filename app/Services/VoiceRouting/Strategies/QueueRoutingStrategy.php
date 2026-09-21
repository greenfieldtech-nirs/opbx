<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\QueueCall;
use App\Services\CallQueue\AcdWorkerClient;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Routes calls into a call queue.
 *
 * The caller is enqueued in the acd-worker (FIFO) and hears music on hold
 * while a poll endpoint asks the worker for an available agent. When an agent
 * is available, the poll endpoint returns a <Dial> to the agent's extension.
 *
 * HARD RULE: queue offers dial the agent's extension directly. Follow-me and
 * call-forward chaining are NEVER applied to queue calls.
 */
class QueueRoutingStrategy implements RoutingStrategy
{
    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::QUEUE;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        /** @var CallQueue|null $callQueue */
        $callQueue = $destination['call_queue'] ?? null;

        if (! $callQueue) {
            return response(CxmlBuilder::unavailable('Call queue not found'), 200, ['Content-Type' => 'application/xml']);
        }

        if (! $callQueue->isActive()) {
            return response(CxmlBuilder::unavailable('Call queue is inactive'), 200, ['Content-Type' => 'application/xml']);
        }

        $callId = (string) $request->input('CallSid');

        if ($callId === '') {
            Log::warning('QueueRoutingStrategy: Missing CallSid, cannot enqueue');

            return response(CxmlBuilder::unavailable('Queue system error'), 200, ['Content-Type' => 'application/xml']);
        }

        $organizationId = (int) $request->input('_organization_id');

        // Record the queue call for statistics. Unique (queue, call) constraint makes
        // this idempotent against webhook retries.
        QueueCall::firstOrCreate(
            [
                'call_queue_id' => $callQueue->id,
                'call_id' => $callId,
            ],
            [
                'organization_id' => $organizationId,
                'from_number' => $request->input('From'),
                'to_number' => $request->input('To'),
                'entered_at' => now(),
            ]
        );

        // Enqueue in the ACD worker (Redis). Failure is tolerated: the poll endpoint
        // re-enqueues on worker recovery.
        app(AcdWorkerClient::class)->enqueue($organizationId, $callQueue->id, $callId);

        Log::info('QueueRoutingStrategy: Call enqueued', [
            'call_queue_id' => $callQueue->id,
            'call_queue_name' => $callQueue->name,
            'call_id' => $callId,
            'organization_id' => $organizationId,
        ]);

        return $this->holdResponse($request, $callQueue, $callId);
    }

    /**
     * Hold CXML: play MOH (if configured) then redirect to the poll endpoint.
     */
    public function holdResponse(Request $request, CallQueue $callQueue, string $callId): Response
    {
        $builder = new CxmlBuilder;

        $mohUrl = $this->resolveMohUrl($request, $callQueue);
        if ($mohUrl !== null) {
            $builder->play($mohUrl);
        } else {
            $builder->say('Please hold the line.');
        }

        $builder->redirect($this->getPollUrl($request, $callQueue, $callId));

        return $builder->toResponse();
    }

    /**
     * Signed URL of the queue's MOH recording, or null when not configured.
     */
    private function resolveMohUrl(Request $request, CallQueue $callQueue): ?string
    {
        if (! $callQueue->moh_recording_id) {
            return null;
        }

        $recording = $callQueue->mohRecording()->withoutGlobalScope(\App\Scopes\OrganizationScope::class)->first();

        if (! $recording || ! $recording->file_path) {
            return null;
        }

        return \App\Http\Controllers\Api\RecordingsController::generateSignedRecordingUrl(
            $this->baseUrl($request),
            $callQueue->organization_id,
            $recording->file_path,
            3600
        );
    }

    private function baseUrl(Request $request): string
    {
        $organizationId = (int) $request->input('_organization_id');
        $cloudonixSettings = CloudonixSettings::where('organization_id', $organizationId)->first();

        return rtrim(
            $cloudonixSettings?->effective_webhook_base_url ?? config('app.url'),
            '/'
        );
    }

    private function getPollUrl(Request $request, CallQueue $callQueue, string $callId): string
    {
        $sessionData = json_encode([
            'call_queue_id' => $callQueue->id,
            'call_id' => $callId,
            'organization_id' => $callQueue->organization_id,
            'callback_type' => 'queue_poll',
        ]);

        return $this->baseUrl($request).route('voice.queue-poll', ['session_data' => $sessionData], false);
    }
}
