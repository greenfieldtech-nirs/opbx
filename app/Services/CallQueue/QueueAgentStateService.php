<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use App\Models\CallQueue;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;

/**
 * Sets a queue agent's presence state in the acd-worker.
 *
 * Shared by the REST endpoint (UI widget / admin toggle) and the *45 dial-in
 * feature code. Seeds least_talk_time / fewest_calls counters from MySQL at
 * login so strategies stay correct across worker restarts.
 */
class QueueAgentStateService
{
    public function __construct(
        private readonly AcdWorkerClient $worker,
    ) {}

    /**
     * Set the agent state: available | wrap_up (sticky) | logged_out.
     *
     * @param  array{wrapUpSeconds?: int}  $extra
     * @return bool whether the worker accepted the state change
     */
    public function setState(CallQueue $callQueue, int $userId, string $state, array $extra = []): bool
    {
        $payload = $extra;

        if ($state === 'available') {
            $totals = QueueCall::withoutGlobalScope(OrganizationScope::class)
                ->where('call_queue_id', $callQueue->id)
                ->where('agent_user_id', $userId)
                ->where('disposition', 'answered')
                ->selectRaw('COALESCE(SUM(handling_seconds), 0) as talk_seconds, COUNT(*) as calls_handled')
                ->first();

            $payload['talkSecondsTotal'] = (int) ($totals->talk_seconds ?? 0);
            $payload['callsHandledTotal'] = (int) ($totals->calls_handled ?? 0);
        }

        // Manual wrap-up (dial-in / UI toggle) is sticky: no auto-expiry.
        if ($state === 'wrap_up' && ! isset($payload['wrapUpSeconds'])) {
            $payload['wrapUpSeconds'] = 0;
        }

        $accepted = $this->worker->setAgentState(
            $callQueue->organization_id,
            $callQueue->id,
            $userId,
            $state,
            $payload
        );

        // Immediate connect: a newly available agent may interrupt the hold of
        // waiting callers instead of waiting for the next poll cycle.
        if ($accepted && $state === 'available') {
            app(ImmediateConnectService::class)->connectAvailableCallers($callQueue);
        }

        return $accepted;
    }

    /**
     * Current worker-side state for an agent (LOGGED_OUT when unknown).
     */
    public function currentState(CallQueue $callQueue, int $userId, string $extensionNumber): string
    {
        $live = $this->worker->live($callQueue->organization_id, $callQueue->id, [
            ['userId' => $userId, 'extensionNumber' => $extensionNumber],
        ]);

        $agent = collect($live['agents'] ?? [])->firstWhere('userId', (string) $userId);

        return $agent['state'] ?? 'LOGGED_OUT';
    }
}
