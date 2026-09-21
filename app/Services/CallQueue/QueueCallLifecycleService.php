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

        $queueCall = $this->findPendingQueueCall(
            $sessionUpdate->organization_id,
            $sessionUpdate->call_ids ?? []
        );

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

        $queueCall->update([
            'answered_at' => $sessionUpdate->call_answer_time ?? $sessionUpdate->session_modified_at ?? now(),
            'agent_user_id' => $agentUserId,
        ]);

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

        if (! $cdrCallId) {
            return;
        }

        $queueCall = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('call_id', $cdrCallId)
            ->whereNull('disposition')
            ->first();

        if (! $queueCall) {
            return;
        }

        $callQueue = CallQueue::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->find($queueCall->call_queue_id);

        $session = $payload['session'] ?? [];
        $answerAt = isset($session['callAnswerTime']) && $session['callAnswerTime'] > 0
            ? \Illuminate\Support\Carbon::createFromTimestampMs($session['callAnswerTime'])
            : null;
        $endAt = isset($session['callEndTime']) && $session['callEndTime'] > 0
            ? \Illuminate\Support\Carbon::createFromTimestampMs($session['callEndTime'])
            : now();

        $enteredAt = $queueCall->entered_at;

        if ($answerAt) {
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

            $queueCall->update([
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
        }

        Redis::del(self::DIAL_MARKER_PREFIX.$queueCall->call_id);

        Log::info('Queue call finalized from CDR', [
            'queue_call_id' => $queueCall->id,
            'call_id' => $queueCall->call_id,
            'disposition' => $queueCall->disposition->value,
        ]);
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
