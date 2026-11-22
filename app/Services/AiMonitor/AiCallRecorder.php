<?php

declare(strict_types=1);

namespace App\Services\AiMonitor;

use App\Enums\AiCallAttemptSource;
use App\Enums\AiCallAttemptStatus;
use App\Models\AiCallAttempt;
use App\Models\AutoDialerCallSession;
use App\Scopes\OrganizationScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Records each hand-off of a call to an AI assistant for the AI Real Time Monitor.
 *
 * Every method is best-effort: monitoring must never break call routing, so a failure is
 * logged and swallowed. Callers run without an authenticated user, so the tenant scope is
 * bypassed and the organisation is always passed explicitly.
 */
class AiCallRecorder
{
    /** Cloudonix CDR dispositions that mean the call was answered. */
    private const ANSWERED_DISPOSITIONS = ['CONNECTED', 'ANSWERED', 'ANSWER'];

    /** The session token Cloudonix sends on voice requests; the CDR carries the same value. */
    public static function callIdFrom(Request $request): ?string
    {
        $id = $request->input('CallSid')
            ?? $request->header('X-Cx-Session')
            ?? $request->input('SessionData.callSid')
            ?? $request->input('Session')
            ?? $request->input('session');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function started(
        int $organizationId,
        int $aiAssistantId,
        ?int $loadBalancerId,
        ?string $callId,
        AiCallAttemptSource $source,
    ): void {
        if ($callId === null || $callId === '') {
            return;
        }

        $this->safely('start', $callId, fn () => $this->query()->create([
            'organization_id' => $organizationId,
            'ai_assistant_id' => $aiAssistantId,
            'ai_assistant_load_balancer_id' => $loadBalancerId,
            'call_id' => $callId,
            'source' => $source,
            'status' => AiCallAttemptStatus::IN_PROGRESS,
            'started_at' => now(),
        ]));
    }

    /**
     * A load-balancer member failed. $fellBack: the load balancer then used its fallback;
     * otherwise it moved on to the next member.
     */
    public function failed(int $organizationId, ?string $callId, int $aiAssistantId, ?string $reason, bool $fellBack): void
    {
        if ($callId === null) {
            return;
        }

        $this->safely('failure', $callId, function () use ($organizationId, $callId, $aiAssistantId, $reason, $fellBack) {
            $attempt = $this->query()
                ->where('organization_id', $organizationId)
                ->where('call_id', $callId)
                ->where('ai_assistant_id', $aiAssistantId)
                ->where('status', AiCallAttemptStatus::IN_PROGRESS)
                ->latest('id')
                ->first();

            $attempt?->update([
                'status' => AiCallAttemptStatus::FAILED,
                'failure_reason' => $reason !== null ? substr($reason, 0, 50) : null,
                'failed_over' => ! $fellBack,
                'fell_back' => $fellBack,
                'ended_at' => now(),
                'duration' => (int) $attempt->started_at->diffInSeconds(now(), true),
                'billsec' => 0,
            ]);
        });
    }

    /** A load balancer had no member to offer and went straight to its fallback. */
    public function fellBackWithoutMember(int $organizationId, int $loadBalancerId, ?string $callId): void
    {
        if ($callId === null) {
            return;
        }

        $this->safely('fallback', $callId, fn () => $this->query()->create([
            'organization_id' => $organizationId,
            'ai_assistant_load_balancer_id' => $loadBalancerId,
            'call_id' => $callId,
            'source' => AiCallAttemptSource::LOAD_BALANCER,
            'status' => AiCallAttemptStatus::FAILED,
            'failure_reason' => 'no_available_assistant',
            'fell_back' => true,
            'started_at' => now(),
            'ended_at' => now(),
            'duration' => 0,
            'billsec' => 0,
        ]));
    }

    /** The call ended: settle the attempt that still had the call. */
    public function callEnded(int $organizationId, array $cdr): void
    {
        $ids = array_values(array_filter([
            $cdr['session']['token'] ?? $cdr['session_token'] ?? null,
            $cdr['call_id'] ?? null,
        ], fn ($id) => is_string($id) && $id !== ''));

        if ($ids === []) {
            return;
        }

        $this->safely('cdr', $ids[0], function () use ($organizationId, $ids, $cdr) {
            // One query for every CDR: most calls never reached an AI assistant.
            $attempts = $this->query()
                ->where('organization_id', $organizationId)
                ->whereIn('call_id', $ids)
                ->orderBy('id')
                ->get();

            if ($attempts->isEmpty()) {
                return;
            }

            $answered = in_array(strtoupper((string) ($cdr['disposition'] ?? '')), self::ANSWERED_DISPOSITIONS, true);
            $endedAt = isset($cdr['session']['callEndTime'])
                ? Carbon::createFromTimestampMs((int) $cdr['session']['callEndTime'])
                : now();

            // A campaign call counts only when a person answered; otherwise it counts for no
            // assistant, including members that already failed on it before a failover.
            $fromAutoDialer = $attempts->contains(fn (AiCallAttempt $a) => $a->source === AiCallAttemptSource::AUTO_DIALER);
            if ($fromAutoDialer && (! $answered || ! $this->reachedPerson($organizationId, $ids, $cdr))) {
                foreach ($attempts as $attempt) {
                    $attempt->update([
                        'status' => AiCallAttemptStatus::EXCLUDED,
                        'failure_reason' => null,
                        'ended_at' => $attempt->ended_at ?? $endedAt,
                        'billsec' => 0,
                    ]);
                }

                return;
            }

            $open = $attempts->where('status', AiCallAttemptStatus::IN_PROGRESS)->values();
            if ($open->isEmpty()) {
                return;
            }

            $last = $open->pop();
            // Earlier open attempts lost their failure callback; the call moved past them.
            foreach ($open as $stale) {
                $stale->update(['status' => AiCallAttemptStatus::FAILED, 'failure_reason' => 'no_result', 'ended_at' => $endedAt]);
            }

            $status = $answered ? AiCallAttemptStatus::ANSWERED : AiCallAttemptStatus::FAILED;

            // The CDR covers the whole call; this assistant only had it from its hand-off on.
            $segment = max(0, (int) $last->started_at->diffInSeconds($endedAt, false));
            $last->update([
                'status' => $status,
                'failure_reason' => $status === AiCallAttemptStatus::FAILED ? substr(strtolower((string) ($cdr['disposition'] ?? 'unknown')), 0, 50) : null,
                'ended_at' => $endedAt,
                'duration' => $segment,
                'billsec' => $answered ? min((int) ($cdr['billsec'] ?? 0), $segment) : 0,
            ]);
        });
    }

    /**
     * Auto-dialer calls count only when a person answered: voicemail detection said "human",
     * or the campaign does not use voicemail detection (no result at all).
     */
    private function reachedPerson(int $organizationId, array $ids, array $cdr): bool
    {
        $result = $cdr['session']['profile']['amd']['result'] ?? null;

        if ($result === null) {
            $result = AutoDialerCallSession::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organizationId)
                ->where(fn ($q) => $q->whereIn('session_token', $ids)->orWhereIn('call_id', $ids))
                ->value('amd_result');
        }

        return $result === null || strtolower((string) $result) === 'human';
    }

    private function query()
    {
        return AiCallAttempt::withoutGlobalScope(OrganizationScope::class);
    }

    private function safely(string $what, string $callId, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('AI monitor: could not record call '.$what, ['call_id' => $callId, 'error' => $e->getMessage()]);
        }
    }
}
