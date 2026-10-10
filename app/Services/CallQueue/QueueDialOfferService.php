<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
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
     * Offer the call to the given agents. Returns null when the caller should
     * keep holding: every offered agent is presence-busy, or the offer runs
     * out-of-band as per-agent conference sessions (conference_ring_all).
     *
     * @param  array<int, array{userId: string, extensionNumber: string}>  $agents
     * @param  bool  $reserve  re-serving the pending offer: no presence filter, offer marker kept as-is
     */
    public function buildDialResponse(Request $request, CallQueue $callQueue, QueueCall $queueCall, array $agents, bool $reserve = false): ?Response
    {
        $conferenceOffers = app(ConferenceOfferService::class);

        if (! $reserve && $conferenceOffers->isEnabledFor($callQueue)) {
            $conferenceOffers->offer($callQueue, $queueCall, $agents);

            return null;
        }

        // Belt-and-suspenders: skip agents whose extension is on an active call.
        // Bypassed when re-serving an in-flight offer: the agent looks busy
        // precisely because of our own dial leg (ringing or bridged).
        $targets = [];
        $agentUserIds = [];
        foreach ($agents as $agent) {
            if (! $reserve && $this->presence->isBusy($callQueue->organization_id, (string) $agent['extensionNumber'])) {
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

        // Remember every offered agent: the marker drives bridge detection and
        // stale-poll re-serves, and attributes the answer when only one agent
        // was offered. A re-serve must not shrink the original offer set.
        if (! $reserve) {
            $this->lifecycle->markDial($callQueue->id, $queueCall->call_id, $agentUserIds);
        }

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

        $cloudonixSettings = CloudonixSettings::where('organization_id', $callQueue->organization_id)->first();
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
            ->withoutGlobalScope(OrganizationScope::class)
            ->with(['extension' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
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
