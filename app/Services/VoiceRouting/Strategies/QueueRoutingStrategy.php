<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Http\Controllers\Api\RecordingsController;
use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\AcdWorkerClient;
use App\Services\CallQueue\MohSegmentationService;
use App\Services\CallQueue\QueueDialOfferService;
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
        $queueCall = QueueCall::firstOrCreate(
            [
                'call_queue_id' => $callQueue->id,
                'call_id' => $callId,
            ],
            [
                'organization_id' => $organizationId,
                'from_number' => $request->input('From'),
                'to_number' => $request->input('To'),
                'session_token' => $request->input('Session') ?: null,
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

        // Immediate connect: when agents are already available, dial straight from
        // the initial routing response. Returning the hold CXML and THEN switching
        // the application races the in-flight <Play> - the caller keeps hearing
        // MOH until the track ends while the agent is already ringing.
        // Only fresh entries dial immediately; a route() retry for a call that
        // is already ringing (or finalized) must fall through to the hold path.
        if ($callQueue->immediate_connect && $queueCall->wasRecentlyCreated) {
            $dialOffer = app(QueueDialOfferService::class);
            $result = app(AcdWorkerClient::class)->poll(
                $organizationId,
                $callQueue->id,
                $callId,
                $dialOffer->roster($callQueue),
                $callQueue->strategy->value,
                $callQueue->max_wait_seconds
            );

            if (($result['action'] ?? 'wait') === 'dial') {
                $dialResponse = $dialOffer->buildDialResponse($request, $callQueue, $queueCall, $result['agents'] ?? []);

                if ($dialResponse !== null) {
                    Log::info('QueueRoutingStrategy: Immediate connect, dialing agent directly', [
                        'call_queue_id' => $callQueue->id,
                        'call_id' => $callId,
                    ]);

                    return $dialResponse;
                }
            }
        }

        return $this->holdResponse($request, $callQueue, $callId);
    }

    /**
     * Hold CXML: announce queue position (when enabled and a position is known),
     * play one MOH segment (segmented recordings) or the full MOH file, then
     * redirect to the poll endpoint. Segmented MOH keeps the poll loop
     * responsive regardless of the MOH file length (WP1, Scenario 2).
     *
     * @param  int|null  $position  Current queue position (from the worker), if known
     * @param  int  $mohIdx  MOH segment index carried across poll cycles via session_data
     */
    public function holdResponse(Request $request, CallQueue $callQueue, string $callId, ?int $position = null, int $mohIdx = 0): Response
    {
        $builder = new CxmlBuilder;

        if ($position !== null && $callQueue->announce_position) {
            $builder->say(
                "You are caller number {$position} in the queue.",
                null,
                $callQueue->announce_position_language
            );
        }

        [$mohUrl, $nextMohIdx] = $this->resolveMoh($request, $callQueue, $mohIdx);
        if ($mohUrl !== null) {
            $builder->play($mohUrl);
        } else {
            // No MOH configured: speak the hold prompt, then pause briefly so
            // the poll loop does not hammer the endpoint back-to-back.
            $builder->say('Please hold the line.')->pause(5);
        }

        $builder->redirect($this->getPollUrl($request, $callQueue, $callId, $nextMohIdx));

        return $builder->toResponse();
    }

    /**
     * Resolve what to play during the hold: the signed URL plus the segment
     * index to carry into the next poll cycle (null when unsegmented).
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function resolveMoh(Request $request, CallQueue $callQueue, int $mohIdx): array
    {
        if (! $callQueue->moh_recording_id) {
            return [null, null];
        }

        $recording = $callQueue->mohRecording()->withoutGlobalScope(OrganizationScope::class)->first();

        if (! $recording || ! $recording->file_path) {
            return [null, null];
        }

        $segments = app(MohSegmentationService::class)->segmentsFor($recording);

        if ($segments !== []) {
            $count = count($segments);
            $index = $mohIdx % $count;

            return [
                $this->signedRecordingUrl($request, $callQueue, $this->stripOrgPrefix($segments[$index], $callQueue->organization_id)),
                ($index + 1) % $count,
            ];
        }

        return [$this->signedRecordingUrl($request, $callQueue, $recording->file_path), null];
    }

    private function signedRecordingUrl(Request $request, CallQueue $callQueue, string $filename): string
    {
        return RecordingsController::generateSignedRecordingUrl(
            $this->baseUrl($request),
            $callQueue->organization_id,
            $filename,
            3600
        );
    }

    /**
     * Segment paths include the org prefix; generateSignedRecordingUrl re-adds it.
     */
    private function stripOrgPrefix(string $path, int $organizationId): string
    {
        $prefix = $organizationId.'/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
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

    private function getPollUrl(Request $request, CallQueue $callQueue, string $callId, ?int $nextMohIdx = null): string
    {
        $sessionData = [
            'call_queue_id' => $callQueue->id,
            'call_id' => $callId,
            'organization_id' => $callQueue->organization_id,
            'callback_type' => 'queue_poll',
        ];

        if ($nextMohIdx !== null) {
            $sessionData['moh_idx'] = $nextMohIdx;
        }

        return $this->baseUrl($request).route('voice.queue-poll', ['session_data' => json_encode($sessionData)], false);
    }
}
