<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use App\Enums\QueueCallDisposition;
use App\Jobs\ReconcileQueueCallDispositionJob;
use App\Models\CallQueue;
use App\Models\QueueCall;
use App\Models\SessionUpdate;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Maintains the queue_calls statistical records and forwards lifecycle
 * events to the acd-worker, driven by Cloudonix webhooks.
 *
 * Correlation: a queued call is matched by its Cloudonix call id appearing
 * in the session update's call_ids (or the CDR's call_id).
 */
class QueueCallLifecycleService
{
    private const DIAL_MARKER_PREFIX = 'acd:dial:';

    private const DIAL_MARKER_TTL_SECONDS = 2 * 60 * 60;

    public function __construct(
        private readonly AcdWorkerClient $worker,
        private readonly ExtensionPresenceTracker $presence,
    ) {}

    /**
     * Remember which agents a queued call was offered to. The marker doubles
     * as the "dial offer pending" signal for bridge detection.
     *
     * @param  int|array<int, int>  $agentUserIds  every agent in the <Dial> (ring_all offers several)
     */
    public function markDial(int $queueId, string $callId, int|array $agentUserIds): void
    {
        $ids = implode(',', array_map('intval', (array) $agentUserIds));

        Redis::setex(self::DIAL_MARKER_PREFIX.$callId, self::DIAL_MARKER_TTL_SECONDS, $queueId.':'.$ids);
    }

    /**
     * Process a Cloudonix session update for queue call correlation.
     */
    public function handleSessionUpdate(SessionUpdate $sessionUpdate): void
    {
        $this->trackSessionState(
            $sessionUpdate->organization_id,
            $sessionUpdate->destination,
            $sessionUpdate->status,
            $sessionUpdate->action,
            $sessionUpdate->session_token,
            $sessionUpdate->session_modified_at?->getTimestampMs()
        );

        // Session updates correlate by session token: call_ids holds SIP call
        // ids, while queue calls are keyed by the voice CallSid (= session token).
        $queueCall = $sessionUpdate->session_token
            ? $this->findPendingQueueCall($sessionUpdate->organization_id, [$sessionUpdate->session_token])
            : null;

        if (! $queueCall && ! empty($sessionUpdate->call_ids)) {
            $queueCall = $this->findPendingQueueCall($sessionUpdate->organization_id, $sessionUpdate->call_ids);
        }

        if (! $queueCall) {
            return;
        }

        // 'connected' is the bridge status for answered queue dials (carries
        // callAnswerTime); it also fires at the initial platform answer, which
        // the dial-marker requirement already excludes.
        if (! in_array($sessionUpdate->status, ['answer', 'answered', 'active', 'connected', 'connect'], true)) {
            return;
        }

        if ($queueCall->answered_at !== null) {
            return;
        }

        // The session's own answer time is the INITIAL answer (caller connected
        // to the voice platform) - it includes the queue wait. The agent bridge
        // is the first answered-status update that arrives while a dial offer is
        // pending (the initial answer precedes any dial marker). Without the
        // marker check, waiting/handling times are computed against the wrong
        // anchor and both metrics are wrong.
        if ($this->readDialMarker($queueCall->call_id)[0] === null) {
            return;
        }

        // Null for a multi-target (ring_all) offer: the session carries no
        // reliable winning leg (outgoingSubscriberId is the CALLER), so no
        // agent is credited rather than the wrong one.
        $agentUserId = $this->attributedAgentId($queueCall->call_id);

        // Claim-first: concurrent 'answer' updates (and the conference claim)
        // must produce exactly one 'answered' event.
        if (! $this->claimPending($queueCall, [
            'answered_at' => $sessionUpdate->session_modified_at ?? $sessionUpdate->created_at ?? now(),
            'agent_user_id' => $agentUserId,
        ], unansweredOnly: true)) {
            return;
        }

        // Candidate bridge timestamp, confirmed later by the dial action
        // callback result (Cloudonix also emits spurious 'answer' updates at
        // call teardown while a dial offer is pending).
        Redis::setex("acd:bridge:{$queueCall->call_id}", self::DIAL_MARKER_TTL_SECONDS, $queueCall->answered_at->toIso8601String());

        Log::info('Queue call answered', [
            'queue_call_id' => $queueCall->id,
            'call_id' => $queueCall->call_id,
            'agent_user_id' => $agentUserId,
        ]);

        $this->worker->event(
            $queueCall->organization_id,
            $queueCall->call_queue_id,
            $queueCall->call_id,
            'answered',
            $agentUserId
        );
    }

    /**
     * A terminal or deleted session update that the webhook does not store
     * (its status is outside the notification allow-list, e.g. 'completed'):
     * it must still end presence and release an unjoined conference winner.
     */
    public function handleSessionEnd(int $organizationId, ?string $destination, string $status, ?string $action, ?string $sessionToken, ?int $modifiedAtMs): void
    {
        $this->trackSessionState($organizationId, $destination, $status, $action, $sessionToken, $modifiedAtMs);
    }

    private function trackSessionState(int $organizationId, ?string $destination, string $status, ?string $action, ?string $sessionToken, ?int $modifiedAtMs): void
    {
        $this->presence->handleSessionUpdate($organizationId, $destination, $status, $action, $modifiedAtMs);

        if ($sessionToken && ExtensionPresenceTracker::endsSession($status, $action)) {
            app(ConferenceOfferService::class)->releaseWinnerIfUnjoined($organizationId, $sessionToken);
        }
    }

    /**
     * Record a conference-mediated bridge: the winning agent is known exactly
     * (it claimed the call from its own session), so the bridge is confirmed
     * immediately and the CDR finalization credits the right agent.
     */
    public function markConferenceBridge(QueueCall $queueCall, int $agentUserId): bool
    {
        // The call may have been finalized (caller hung up) while the agent answered.
        if (! $this->claimPending($queueCall, [
            'answered_at' => now(),
            'agent_user_id' => $agentUserId,
        ])) {
            return false;
        }

        Redis::setex("acd:bridge:{$queueCall->call_id}", self::DIAL_MARKER_TTL_SECONDS, $queueCall->answered_at->toIso8601String());
        $this->recordDialResult($queueCall->call_id, 'answered');

        Log::info('Queue call answered (conference)', [
            'queue_call_id' => $queueCall->id,
            'call_id' => $queueCall->call_id,
            'agent_user_id' => $agentUserId,
        ]);

        $this->worker->event(
            $queueCall->organization_id,
            $queueCall->call_queue_id,
            $queueCall->call_id,
            'answered',
            $agentUserId
        );

        return true;
    }

    /**
     * Undo a conference bridge whose winner never connected: the call is
     * pending again, the agent is released and the call is put back in the
     * worker queue so the next poll re-offers it.
     */
    public function revertConferenceBridge(QueueCall $queueCall, int $agentUserId): bool
    {
        $reverted = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->whereKey($queueCall->getKey())
            ->whereNull('disposition')
            ->where('agent_user_id', $agentUserId)
            ->update(['answered_at' => null, 'agent_user_id' => null]);

        if ($reverted !== 1) {
            return false;
        }

        Redis::del("acd:bridge:{$queueCall->call_id}", "acd:dialresult:{$queueCall->call_id}");

        $this->worker->event(
            $queueCall->organization_id,
            $queueCall->call_queue_id,
            $queueCall->call_id,
            'requeue',
            $agentUserId
        );

        return true;
    }

    /**
     * Process a completed CDR: finalizes the queue call record.
     *
     * @param  array<string, mixed>  $payload  Raw CDR webhook payload (timestamps under session.callStartTime etc., epoch ms)
     */
    public function handleCdr(int $organizationId, array $payload): void
    {
        $cdrCallId = $payload['call_id'] ?? null;
        $sessionToken = $payload['session']['token'] ?? $payload['session_token'] ?? null;

        if (! $cdrCallId && ! $sessionToken) {
            return;
        }

        $queueCall = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($cdrCallId, $sessionToken) {
                if ($sessionToken) {
                    $query->where('call_id', $sessionToken);
                }
                if ($cdrCallId) {
                    $query->orWhere('call_id', $cdrCallId);
                }
            })
            ->whereNull('disposition')
            ->first();

        if (! $queueCall) {
            return;
        }

        // Ordering race: the dial action callback (authoritative bridge result)
        // may arrive after the CDR. If a dial outcome is not recorded yet and
        // the call saw dial activity, park the CDR and reconcile shortly.
        if (Redis::get("acd:dialresult:{$queueCall->call_id}") === null
            && (Redis::exists("acd:dial:{$queueCall->call_id}") || $queueCall->answered_at !== null)) {
            Redis::setex("acd:cdr:{$queueCall->call_id}", 3600, json_encode($payload));

            ReconcileQueueCallDispositionJob::dispatch($queueCall->id)
                ->delay(now()->addSeconds(10));

            Log::info('Queue call CDR parked awaiting dial result', [
                'queue_call_id' => $queueCall->id,
                'call_id' => $queueCall->call_id,
            ]);

            return;
        }

        $this->finalizeFromCdr($organizationId, $payload);
    }

    /**
     * Finalize a queue call from its CDR, gated by the dial callback result.
     */
    public function finalizeFromCdr(int $organizationId, array $payload): void
    {
        $cdrCallId = $payload['call_id'] ?? null;
        $sessionToken = $payload['session']['token'] ?? $payload['session_token'] ?? null;

        $queueCall = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($cdrCallId, $sessionToken) {
                if ($sessionToken) {
                    $query->where('call_id', $sessionToken);
                }
                if ($cdrCallId) {
                    $query->orWhere('call_id', $cdrCallId);
                }
            })
            ->whereNull('disposition')
            ->first();

        if (! $queueCall) {
            return;
        }

        $callQueue = CallQueue::withoutGlobalScope(OrganizationScope::class)
            ->find($queueCall->call_queue_id);

        $dialResult = Redis::get("acd:dialresult:{$queueCall->call_id}");
        $bridgeIso = Redis::get("acd:bridge:{$queueCall->call_id}");

        $session = $payload['session'] ?? [];
        // Prefer the agent-bridge time captured from session updates; the CDR's
        // own callAnswerTime is the initial platform answer (includes queue wait).
        $answerAt = $queueCall->answered_at
            ?? (isset($session['callAnswerTime']) && $session['callAnswerTime'] > 0
                ? Carbon::createFromTimestampMs($session['callAnswerTime'])
                : null);
        $endAt = isset($session['callEndTime']) && $session['callEndTime'] > 0
            ? Carbon::createFromTimestampMs($session['callEndTime'])
            : now();

        $enteredAt = $queueCall->entered_at;

        // An 'answered' disposition requires the dial callback to confirm the
        // agent actually picked up. A spurious teardown 'answer' session update
        // may have set answered_at while the offer was still pending; that is
        // demoted to abandoned here and the agent is released in the worker.
        $bridgeConfirmed = $dialResult === 'answered'
            || ($dialResult === null && $queueCall->answered_at !== null); // callback lost: best effort

        if ($answerAt && $bridgeConfirmed) {
            // Prefer the bridge timestamp captured at the (confirmed) bridge
            // update; fall back to what is stored, then the CDR answer time.
            if ($bridgeIso) {
                $answerAt = Carbon::parse($bridgeIso);
            }

            $waitingSeconds = max(0, (int) $enteredAt->diffInSeconds($answerAt));
            $handlingSeconds = max(0, (int) $answerAt->diffInSeconds($endAt));

            $agentUserId = $this->attributedAgentId($queueCall->call_id);

            // Claim-first: a duplicate CDR / reconcile job finds the row already
            // finalized and must not emit a second 'ended' event.
            if (! $this->claimPending($queueCall, [
                'answered_at' => $answerAt,
                'ended_at' => $endAt,
                'agent_user_id' => $queueCall->agent_user_id ?? $agentUserId,
                'waiting_seconds' => $waitingSeconds,
                'handling_seconds' => $handlingSeconds,
                'disposition' => QueueCallDisposition::ANSWERED,
            ])) {
                return;
            }

            $this->worker->event(
                $queueCall->organization_id,
                $queueCall->call_queue_id,
                $queueCall->call_id,
                'ended',
                $queueCall->agent_user_id ?? $agentUserId,
                $handlingSeconds,
                $callQueue?->wrap_up_seconds
            );
        } else {
            $waitingSeconds = max(0, (int) $enteredAt->diffInSeconds($endAt));
            $demotedAgentId = $queueCall->agent_user_id ?? $this->attributedAgentId($queueCall->call_id);

            if (! $this->claimPending($queueCall, [
                'answered_at' => null,
                'agent_user_id' => null,
                'abandoned_at' => $endAt,
                'waiting_seconds' => $waitingSeconds,
                'disposition' => QueueCallDisposition::ABANDONED,
            ])) {
                return;
            }

            $this->worker->event(
                $queueCall->organization_id,
                $queueCall->call_queue_id,
                $queueCall->call_id,
                'abandoned'
            );

            // A spurious bridge marking may have marked the offered agent BUSY
            // in the worker; release them back to the pool.
            if ($demotedAgentId !== null) {
                $this->worker->event(
                    $queueCall->organization_id,
                    $queueCall->call_queue_id,
                    $queueCall->call_id,
                    'dial_failed',
                    $demotedAgentId
                );
            }

            // Caller left while conference-offer agent sessions still ring.
            if ($callQueue) {
                app(ConferenceOfferService::class)->cancel($callQueue, $queueCall->call_id);
            }
        }

        Redis::del(self::DIAL_MARKER_PREFIX.$queueCall->call_id);
        Redis::del("acd:bridge:{$queueCall->call_id}");
        Redis::del("acd:dialresult:{$queueCall->call_id}");

        Log::info('Queue call finalized from CDR', [
            'queue_call_id' => $queueCall->id,
            'call_id' => $queueCall->call_id,
            'disposition' => $queueCall->disposition->value,
        ]);
    }

    /**
     * Reap ghost entries from the worker queue: waiting callIds that have no
     * pending queue_calls row (their call already ended without the worker
     * being notified). Ghosts at the head would otherwise block the queue
     * forever - nothing ever polls them.
     *
     * @param  array<int, string>  $waitingCallIds
     * @return array<int, string> the reaped ghost call ids
     */
    public function reconcileStaleCalls(CallQueue $callQueue, array $waitingCallIds): array
    {
        if (empty($waitingCallIds)) {
            return [];
        }

        $pending = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->whereIn('call_id', $waitingCallIds)
            ->whereNull('disposition')
            ->pluck('call_id')
            ->all();

        $ghosts = array_values(array_diff($waitingCallIds, $pending));

        foreach ($ghosts as $ghostCallId) {
            Log::info('QueueCallLifecycleService: reaping ghost queue entry', [
                'call_queue_id' => $callQueue->id,
                'call_id' => $ghostCallId,
            ]);

            $this->worker->event($callQueue->organization_id, $callQueue->id, $ghostCallId, 'abandoned');
        }

        return $ghosts;
    }

    /**
     * Record the dial action callback result - the authoritative bridge outcome.
     * 'answered'/'completed' mean the agent actually picked up; everything else
     * (busy/no-answer/failed/canceled) means no bridge happened.
     */
    public function recordDialResult(string $callId, string $callStatus): void
    {
        $result = in_array($callStatus, ['answered', 'completed'], true)
            ? 'answered'
            : 'failed';

        Redis::setex("acd:dialresult:{$callId}", self::DIAL_MARKER_TTL_SECONDS, $result);
    }

    /**
     * Handle a failed agent dial (no-answer/busy): release the agent back to the pool.
     */
    public function handleDialFailed(CallQueue $queue, string $callId): void
    {
        [$markerQueueId] = $this->readDialMarker($callId);
        $agentUserId = $this->attributedAgentId($callId);

        // A ring_all offer never marked its agents busy: nothing to release.
        if ($agentUserId === null || $markerQueueId !== $queue->id) {
            return;
        }

        $this->worker->event(
            $queue->organization_id,
            $queue->id,
            $callId,
            'dial_failed',
            $agentUserId
        );
    }

    /**
     * Mark a queue call as overflowed (max wait exceeded).
     */
    public function markOverflowed(QueueCall $queueCall): void
    {
        if (! $this->claimPending($queueCall, ['disposition' => QueueCallDisposition::OVERFLOW])) {
            return;
        }

        $this->worker->event(
            $queueCall->organization_id,
            $queueCall->call_queue_id,
            $queueCall->call_id,
            'overflow'
        );

        Redis::del(self::DIAL_MARKER_PREFIX.$queueCall->call_id);

        $callQueue = CallQueue::withoutGlobalScope(OrganizationScope::class)->find($queueCall->call_queue_id);

        if ($callQueue) {
            app(ConferenceOfferService::class)->cancel($callQueue, $queueCall->call_id);
        }
    }

    /**
     * Conditional UPDATE ... WHERE disposition IS NULL: true only for the one
     * caller that changed the row. On success the model mirrors the write.
     *
     * @param  array<string, mixed>  $attributes
     * @param  bool  $unansweredOnly  also require answered_at IS NULL (first answer wins)
     */
    private function claimPending(QueueCall $queueCall, array $attributes, bool $unansweredOnly = false): bool
    {
        $query = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->whereKey($queueCall->getKey())
            ->whereNull('disposition');

        if ($unansweredOnly) {
            $query->whereNull('answered_at');
        }

        $values = array_map(
            fn ($value) => $value instanceof \BackedEnum ? $value->value : $value,
            $attributes
        );

        if ($query->update($values) !== 1) {
            Log::info('Queue call already claimed, skipping duplicate transition', [
                'queue_call_id' => $queueCall->id,
                'call_id' => $queueCall->call_id,
            ]);

            return false;
        }

        $queueCall->forceFill($attributes)->syncOriginal();

        return true;
    }

    private function findPendingQueueCall(int $organizationId, array $callIds): ?QueueCall
    {
        if (empty($callIds)) {
            return null;
        }

        return QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->whereIn('call_id', $callIds)
            ->whereNull('disposition')
            ->orderBy('id')
            ->first();
    }

    /**
     * Agents offered this call by the pending <Dial>, in offer order (empty when none).
     *
     * @return array<int, int>
     */
    public function dialOfferAgentIds(string $callId): array
    {
        return $this->readDialMarker($callId)[1];
    }

    /**
     * The agent a bridge can be credited to: exact for a single-target offer,
     * null for a multi-target (ring_all) offer whose winning leg is unknown.
     */
    private function attributedAgentId(string $callId): ?int
    {
        $offered = $this->dialOfferAgentIds($callId);

        return count($offered) === 1 ? $offered[0] : null;
    }

    /**
     * @return array{0: int|null, 1: array<int, int>} queue id and offered agent user ids
     */
    private function readDialMarker(string $callId): array
    {
        $value = Redis::get(self::DIAL_MARKER_PREFIX.$callId);

        if (! $value || ! str_contains($value, ':')) {
            return [null, []];
        }

        [$queueId, $agentUserIds] = explode(':', $value, 2);

        return [(int) $queueId, array_map('intval', array_filter(explode(',', $agentUserIds), 'strlen'))];
    }
}
