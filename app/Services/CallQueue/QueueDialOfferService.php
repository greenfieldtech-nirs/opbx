<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use App\Models\CallQueue;
use App\Models\QueueCall;
use App\Services\CallRecording\CallRecordingDecisionService;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\Strategies\QueueRoutingStrategy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Builds the agent <Dial> offer for a queued call.
 *
 * Shared by the poll path (QueuePollController) and the proactive immediate
 * connect path (QueueImmediateDialController): presence filter, dial marker,
 * and the <Dial> CXML with the dial-result callback.
 */
class QueueDialOfferService
{
    public function __construct(
        private readonly ExtensionPresenceTracker $presence,
        private readonly QueueCallLifecycleService $lifecycle,
    ) {}

    // Calls entering a queue are inbound calls: the agent <Dial> must abide by
    // the organization's inbound call recording policy (same as ring groups).

    /**
     * Offer the call to the given agents. Returns null when every offered
     * agent is presence-busy (caller should keep holding).
     *
     * @param  array<int, array{userId: string, extensionNumber: string}>  $agents
     */
    public function buildDialResponse(Request $request, CallQueue $callQueue, QueueCall $queueCall, array $agents, bool $respectPresence = true): ?Response
    {
        // Belt-and-suspenders: skip agents whose extension is on an active call.
        // Bypassed when re-serving an in-flight offer: the agent looks busy
        // precisely because of our own dial leg (ringing or bridged).
        $targets = [];
        $agentUserIds = [];
        foreach ($agents as $agent) {
            if ($respectPresence && $this->presence->isBusy($callQueue->organization_id, (string) $agent['extensionNumber'])) {
                Log::info('QueueDialOfferService: Skipping presence-busy agent', [
                    'call_queue_id' => $callQueue->id,
                    'call_id' => $queueCall->call_id,
                    'agent_user_id' => $agent['userId'],
                    'extension_number' => $agent['extensionNumber'],
                ]);
                continue;
            }
            $targets[] = (string) $agent['extensionNumber'];
            $agentUserIds[] = (int) $agent['userId'];
        }

        if (empty($targets)) {
            return null;
        }

        // Remember the offered agents for answer attribution.
        // ponytail: ring_all may offer several agents; we attribute the answer to the
        // first offered agent. Exact per-leg attribution requires bridged-leg CDR
        // correlation — add if ring_all attribution proves too coarse.
        $this->lifecycle->markDial($callQueue->id, $queueCall->call_id, $agentUserIds[0]);

        Log::info('QueueDialOfferService: Offering call to agents', [
            'call_queue_id' => $callQueue->id,
            'call_queue_name' => $callQueue->name,
            'call_id' => $queueCall->call_id,
            'targets' => $targets,
            'timeout' => $callQueue->agent_ring_timeout,
        ]);

        $recording = app(CallRecordingDecisionService::class)
            ->resolve($callQueue->organization_id, 'inbound');

        $builder = new CxmlBuilder;
        $builder->dial(
            $targets,
            $callQueue->agent_ring_timeout,
            $this->getDialCallbackUrl($request, $callQueue, $queueCall->call_id),
            record: $recording->record,
            recordingStatusCallback: $recording->recordingStatusCallback,
        );

        return $builder->toResponse();
    }

    private function getDialCallbackUrl(Request $request, CallQueue $callQueue, string $callId): string
    {
        $sessionData = json_encode([
            'call_queue_id' => $callQueue->id,
            'call_id' => $callId,
            'organization_id' => $callQueue->organization_id,
            'callback_type' => 'queue_dial_callback',
        ]);

        $cloudonixSettings = \App\Models\CloudonixSettings::where('organization_id', $callQueue->organization_id)->first();
        $baseUrl = rtrim($cloudonixSettings?->effective_webhook_base_url ?? config('app.url'), '/');

        return $baseUrl.route('voice.queue-dial-callback', ['session_data' => $sessionData], false);
    }

    /**
     * Hold CXML convenience wrapper (no position announcement).
     */
    public function holdResponse(Request $request, CallQueue $callQueue, string $callId): Response
    {
        return app(QueueRoutingStrategy::class)->holdResponse($request, $callQueue, $callId);
    }

    /**
     * Agent roster (queue members with extensions) for worker poll/live calls.
     *
     * @return array<int, array{userId: int, extensionNumber: string}>
     */
    public function roster(CallQueue $callQueue): array
    {
        return $callQueue->agents()
            ->withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->with(['extension' => fn ($q) => $q->withoutGlobalScope(\App\Scopes\OrganizationScope::class)])
            ->get()
            ->filter(fn ($user) => $user->extension !== null)
            ->map(fn ($user) => [
                'userId' => $user->id,
                'extensionNumber' => $user->extension->extension_number,
            ])
            ->values()
            ->all();
    }
}
