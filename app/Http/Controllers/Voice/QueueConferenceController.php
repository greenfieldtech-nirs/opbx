<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\ConferenceOfferService;
use App\Services\CallRecording\CallRecordingDecisionService;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\Strategies\QueueRoutingStrategy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Conference-mediated ring-all endpoints (HMAC-signed URLs, no Bearer auth:
 * originated and application-switched fetches may not carry it).
 *
 * - agentConnect: fetched by an originated agent session once the agent
 *   answers. The first leg claims the call and joins the room; every other
 *   leg hangs up.
 * - callerJoin: fetched by the caller session after the winner claimed the
 *   call (application switch, or the next poll cycle).
 */
class QueueConferenceController extends Controller
{
    public function __construct(
        private readonly ConferenceOfferService $offers,
    ) {}

    public function agentConnect(Request $request): Response
    {
        [$callQueue, $queueCall, $context] = $this->resolve($request);

        $agentUserId = (int) ($context['agent_user_id'] ?? 0);
        $agentSessionToken = (string) ($request->input('Session') ?: $request->input('CallSid'));

        if (! $callQueue || ! $queueCall || $agentUserId === 0 || $agentSessionToken === ''
            || ! $this->offers->claim($callQueue, $queueCall, $agentUserId, $agentSessionToken)) {
            Log::info('QueueConferenceController: agent leg did not win, hanging up', [
                'call_id' => $context['call_id'] ?? null,
                'agent_user_id' => $agentUserId,
            ]);

            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        return (new CxmlBuilder)
            ->dialQueueConference($this->offers->roomName($queueCall))
            ->toResponse();
    }

    public function callerJoin(Request $request): Response
    {
        [$callQueue, $queueCall] = $this->resolve($request);

        if (! $callQueue || ! $queueCall) {
            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        if ($this->offers->winnerOf($queueCall->call_id) === null) {
            // Nothing to join (yet): keep the caller in the hold loop.
            return app(QueueRoutingStrategy::class)->holdResponse($request, $callQueue, $queueCall->call_id);
        }

        return $this->joinResponse($callQueue, $queueCall);
    }

    /**
     * Caller-side room join. Also served by the poll loop when the
     * application switch did not land.
     */
    public function joinResponse(CallQueue $callQueue, QueueCall $queueCall): Response
    {
        $this->offers->markCallerJoined($queueCall->call_id);

        $recording = app(CallRecordingDecisionService::class)
            ->resolve($callQueue->organization_id, 'inbound');

        return (new CxmlBuilder)
            ->dialQueueConference(
                $this->offers->roomName($queueCall),
                record: $recording->record !== null,
                recordingStatusCallback: $recording->recordingStatusCallback,
            )
            ->toResponse();
    }

    /**
     * @return array{0: CallQueue|null, 1: QueueCall|null, 2: array<string, mixed>}
     */
    private function resolve(Request $request): array
    {
        $context = $this->offers->verifiedContext((string) $request->query('sd', ''), (string) $request->query('sig', ''));

        if ($context === null) {
            Log::warning('QueueConferenceController: invalid or expired signature');

            return [null, null, []];
        }

        $callQueue = CallQueue::withoutGlobalScope(OrganizationScope::class)
            ->where('id', (int) ($context['call_queue_id'] ?? 0))
            ->where('organization_id', (int) ($context['organization_id'] ?? 0))
            ->first();

        $queueCall = $callQueue
            ? QueueCall::withoutGlobalScope(OrganizationScope::class)
                ->where('call_queue_id', $callQueue->id)
                ->where('call_id', (string) ($context['call_id'] ?? ''))
                ->whereNull('disposition')
                ->first()
            : null;

        return [$callQueue, $queueCall, $context];
    }
}
