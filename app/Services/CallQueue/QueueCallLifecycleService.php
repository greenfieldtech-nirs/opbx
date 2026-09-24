<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use App\Enums\QueueCallDisposition;
use App\Models\CallQueue;
use App\Models\QueueCall;
use App\Models\SessionUpdate;
use App\Scopes\OrganizationScope;
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
     * Remember which agent a queued call was dialed to (for answer attribution).
     */
    public function markDial(int $queueId, string $callId, int $agentUserId): void
    {
        Redis::setex(self::DIAL_MARKER_PREFIX.$callId, self::DIAL_MARKER_TTL_SECONDS, (string) $queueId.':'.$agentUserId);
    }

    /**
     * Process a Cloudonix session update for queue call correlation.
     */
    public function handleSessionUpdate(SessionUpdate $sessionUpdate): void
    {
        $this->presence->handleSessionUpdate(
            $sessionUpdate->organization_id,
            $sessionUpdate->destination,
            $sessionUpdate->status
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

        if (! in_array($sessionUpdate->status, ['answer', 'answered', 'active'], true)) {
            return;
        }

        if ($queueCall->answered_at !== null) {
            return;
        }

        [, $agentUserId] = $this->readDialMarker($queueCall->call_id);

        // The session's own answer time is the INITIAL answer (caller connected
        // to the voice platform) - it includes the queue wait. The agent bridge
        // is the first answered-status update that arrives while a dial offer is
        // pending (the initial answer precedes any dial marker). Without the
        // marker check, waiting/handling times are computed against the wrong
        // anchor and both metrics are wrong.
        if ($agentUserId === null) {
            return;
        }

        $queueCall->update([
            'answered_at' => $sessionUpdate->session_modified_at ?? $sessionUpdate->created_at ?? now(),
            'agent_user_id' => $agentUserId,
        ]);

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

            \App\Jobs\ReconcileQueueCallDispositionJob::dispatch($queueCall->id)
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

        $callQueue = CallQueue::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->find($queueCall->call_queue_id);

        $dialResult = Redis::get("acd:dialresult:{$queueCall->call_id}");
        $bridgeIso = Redis::get("acd:bridge:{$queueCall->call_id}");

        $session = $payload['session'] ?? [];
        // Prefer the agent-bridge time captured from session updates; the CDR's
        // own callAnswerTime is the initial platform answer (includes queue wait).
        $answerAt = $queueCall->answered_at
            ?? (isset($session['callAnswerTime']) && $session['callAnswerTime'] > 0
                ? \Illuminate\Support\Carbon::createFromTimestampMs($session['callAnswerTime'])
                : null);
        $endAt = isset($session['callEndTime']) && $session['callEndTime'] > 0
            ? \Illuminate\Support\Carbon::createFromTimestampMs($session['callEndTime'])
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
                $answerAt = \Illuminate\Support\Carbon::parse($bridgeIso);
            }

            $waitingSeconds = max(0, (int) $enteredAt->diffInSeconds($answerAt));
            $handlingSeconds = max(0, (int) $answerAt->diffInSeconds($endAt));

            [, $agentUserId] = $this->readDialMarker($queueCall->call_id);

            $queueCall->update([
                'answered_at' => $answerAt,
                'ended_at' => $endAt,
                'agent_user_id' => $queueCall->agent_user_id ?? $agentUserId,
                'waiting_seconds' => $waitingSeconds,
                'handling_seconds' => $handlingSeconds,
                'disposition' => QueueCallDisposition::ANSWERED,
            ]);

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
            $demotedAgentId = $queueCall->agent_user_id ?? $this->readDialMarker($queueCall->call_id)[1];

            $queueCall->update([
                'answered_at' => null,
                'agent_user_id' => null,
                'abandoned_at' => $endAt,
                'waiting_seconds' => $waitingSeconds,
                'disposition' => QueueCallDisposition::ABANDONED,
            ]);

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
     */
    public function reconcileStaleCalls(CallQueue $callQueue, array $waitingCallIds): void
    {
        if (empty($waitingCallIds)) {
            return;
        }

        $pending = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->whereIn('call_id', $waitingCallIds)
            ->whereNull('disposition')
            ->pluck('call_id')
            ->all();

        foreach (array_diff($waitingCallIds, $pending) as $ghostCallId) {
            Log::info('QueueCallLifecycleService: reaping ghost queue entry', [
                'call_queue_id' => $callQueue->id,
                'call_id' => $ghostCallId,
            ]);

            $this->worker->event($callQueue->organization_id, $callQueue->id, $ghostCallId, 'abandoned');
        }
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
        [$markerQueueId, $agentUserId] = $this->readDialMarker($callId);

        if ($agentUserId === null || (int) $markerQueueId !== $queue->id) {
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
        $queueCall->update(['disposition' => QueueCallDisposition::OVERFLOW]);

        $this->worker->event(
            $queueCall->organization_id,
            $queueCall->call_queue_id,
            $queueCall->call_id,
            'overflow'
        );

        Redis::del(self::DIAL_MARKER_PREFIX.$queueCall->call_id);
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
     * Agent currently offered this call (from the dial marker), or null.
     */
    public function dialOfferAgentId(string $callId): ?int
    {
        return $this->readDialMarker($callId)[1];
    }

    /**
     * @return array{0: int|null, 1: int|null} queue id and agent user id from the dial marker
     */
    private function readDialMarker(string $callId): array
    {
        $value = Redis::get(self::DIAL_MARKER_PREFIX.$callId);

        if (! $value || ! str_contains($value, ':')) {
            return [null, null];
        }

        [$queueId, $agentUserId] = explode(':', $value, 2);

        return [(int) $queueId, (int) $agentUserId];
    }
}
