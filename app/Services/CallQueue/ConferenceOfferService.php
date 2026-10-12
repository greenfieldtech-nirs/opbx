<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use App\Enums\CallQueueStrategy;
use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
use App\Services\CloudonixClient\CloudonixCallsClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Conference-mediated ring-all offers (WP3, customer Scenarios 1 + 3).
 *
 * Instead of one multi-target <Dial> inside the caller's session, every
 * offered agent is originated in its OWN Cloudonix session. The first agent
 * to answer claims the call (atomic SET NX) and joins a conference room; the
 * other agent sessions are ended and the caller is switched into the room.
 * A failed or late leg can therefore never touch the caller's session, and
 * the winner is known exactly.
 *
 * The caller keeps holding in the regular poll loop (segmented MOH) while an
 * offer is pending; the poll loop stays the single offer driver.
 *
 * Redis:
 *   acd:confoffer:{callId}   hash   agentUserId => agent session token (pending offer)
 *   acd:confwinner:{callId}  string winning agentUserId
 *   acd:confoffer:lock:{callId} string offer-in-progress lock (SET NX; covers the origination window)
 *   acd:confleg:{sessionToken}  string callId of the winning agent leg (to release the winner if it ends early)
 *   acd:confjoined:{callId}  string set once the caller was served the room join
 */
class ConferenceOfferService
{
    private const OFFER_PREFIX = 'acd:confoffer:';

    private const WINNER_PREFIX = 'acd:confwinner:';

    private const LOCK_PREFIX = 'acd:confoffer:lock:';

    private const LEG_PREFIX = 'acd:confleg:';

    private const JOINED_PREFIX = 'acd:confjoined:';

    private const WINNER_TTL_SECONDS = 2 * 60 * 60;

    // The offer outlives the agents' ring timeout slightly, so an expired
    // offer means every leg has stopped ringing.
    private const OFFER_GRACE_SECONDS = 10;

    // Signed URLs must stay valid until the last agent could answer.
    private const SIGNATURE_GRACE_SECONDS = 60;

    public function __construct(
        private readonly ExtensionPresenceTracker $presence,
        private readonly QueueCallLifecycleService $lifecycle,
    ) {}

    public function isEnabledFor(CallQueue $callQueue): bool
    {
        return $callQueue->conference_ring_all && $callQueue->strategy === CallQueueStrategy::RING_ALL;
    }

    /**
     * Originate one session per agent. Returns false when no leg could be
     * started (all presence-busy or origination failed): the caller keeps
     * holding and the next poll retries.
     *
     * @param  array<int, array{userId: string|int, extensionNumber: string}>  $agents
     */
    public function offer(CallQueue $callQueue, QueueCall $queueCall, array $agents): bool
    {
        if ($this->isOfferPending($queueCall->call_id) || $this->winnerOf($queueCall->call_id) !== null) {
            return true;
        }

        // The offer hash only appears after origination: the lock closes that
        // window so overlapping callers (poll + immediate connect) originate once.
        $lockKey = self::LOCK_PREFIX.$queueCall->call_id;
        if (! Redis::set($lockKey, '1', 'EX', $callQueue->agent_ring_timeout + self::OFFER_GRACE_SECONDS, 'NX')) {
            return true;
        }

        try {
            $sessions = $this->originate($callQueue, $queueCall, $agents);
        } catch (\Throwable $e) {
            Redis::del($lockKey);

            throw $e;
        }

        Log::info('ConferenceOfferService: Offered call to agent sessions', [
            'call_queue_id' => $callQueue->id,
            'call_id' => $queueCall->call_id,
            'offered' => count($agents),
            'originated' => array_keys($sessions),
        ]);

        if ($sessions === []) {
            Redis::del($lockKey);

            return false;
        }

        $key = self::OFFER_PREFIX.$queueCall->call_id;
        Redis::hmset($key, $sessions);
        Redis::expire($key, $callQueue->agent_ring_timeout + self::OFFER_GRACE_SECONDS);

        return true;
    }

    /**
     * @param  array<int, array{userId: string|int, extensionNumber: string}>  $agents
     * @return array<string, string> agentUserId => agent session token
     */
    private function originate(CallQueue $callQueue, QueueCall $queueCall, array $agents): array
    {
        $client = $this->client($callQueue);
        $sessions = [];

        foreach ($agents as $agent) {
            $extension = (string) $agent['extensionNumber'];
            $agentUserId = (int) $agent['userId'];

            if ($this->presence->isBusy($callQueue->organization_id, $extension)) {
                continue;
            }

            $result = $this->safely(fn () => $client?->originateApplicationCall([
                'destination' => $extension,
                'caller-id' => $queueCall->from_number,
                'url' => $this->signedUrl('voice.queue-agent-connect', $callQueue, $queueCall, $agentUserId),
                'timeout' => $callQueue->agent_ring_timeout,
            ]));

            if (! empty($result['token'])) {
                $sessions[(string) $agentUserId] = (string) $result['token'];
            }
        }

        return $sessions;
    }

    public function isOfferPending(string $callId): bool
    {
        return (bool) Redis::exists(self::OFFER_PREFIX.$callId);
    }

    public function winnerOf(string $callId): ?int
    {
        $winner = Redis::get(self::WINNER_PREFIX.$callId);

        return $winner === null ? null : (int) $winner;
    }

    /**
     * An agent leg answered: elect the first one as the winner. Returns false
     * for every other leg (it must hang up instead of joining the room).
     */
    public function claim(CallQueue $callQueue, QueueCall $queueCall, int $agentUserId, string $agentSessionToken): bool
    {
        $offerKey = self::OFFER_PREFIX.$queueCall->call_id;
        $offered = Redis::hgetall($offerKey) ?: [];

        // Only a leg of the CURRENT offer may claim (a late answer from an
        // expired offer would otherwise steal a re-offered call).
        if (($offered[(string) $agentUserId] ?? null) !== $agentSessionToken) {
            return false;
        }

        $won = Redis::set(self::WINNER_PREFIX.$queueCall->call_id, (string) $agentUserId, 'EX', self::WINNER_TTL_SECONDS, 'NX');

        if (! $won) {
            return false;
        }

        $winnerKey = self::WINNER_PREFIX.$queueCall->call_id;

        // The winner key must not outlive a failed bridge write, or the call
        // would be stuck "won" with nobody connected.
        try {
            $bridged = $this->lifecycle->markConferenceBridge($queueCall, $agentUserId);
        } catch (\Throwable $e) {
            Redis::del($winnerKey);

            throw $e;
        }

        if (! $bridged) {
            // The caller left while the agent answered.
            Redis::del($winnerKey);
            $this->cancel($callQueue, $queueCall->call_id);

            return false;
        }

        Redis::setex(self::LEG_PREFIX.$agentSessionToken, self::WINNER_TTL_SECONDS, $queueCall->call_id);
        Redis::del($offerKey);

        $client = $this->client($callQueue);

        foreach ($offered as $userId => $token) {
            if ((int) $userId !== $agentUserId) {
                $this->safely(fn () => $client?->endSession((string) $token));
            }
        }

        // Bring the caller into the room now; the poll loop also serves the
        // join on its next cycle if the switch fails.
        if ($queueCall->session_token) {
            $this->safely(fn () => $client?->switchVoiceApplication(
                $queueCall->session_token,
                $this->signedUrl('voice.queue-conference-join', $callQueue, $queueCall)
            ));
        }

        Log::info('ConferenceOfferService: Agent won the call', [
            'call_queue_id' => $callQueue->id,
            'call_id' => $queueCall->call_id,
            'agent_user_id' => $agentUserId,
            'losers' => array_values(array_diff(array_keys($offered), [(string) $agentUserId])),
        ]);

        return true;
    }

    /**
     * Caller left (abandoned/overflowed) while agents were still ringing:
     * end every pending agent session.
     */
    public function cancel(CallQueue $callQueue, string $callId): void
    {
        $offerKey = self::OFFER_PREFIX.$callId;
        $offered = Redis::hgetall($offerKey) ?: [];
        Redis::del($offerKey, self::LOCK_PREFIX.$callId);

        if ($offered === []) {
            return;
        }

        $client = $this->client($callQueue);

        foreach ($offered as $token) {
            $this->safely(fn () => $client?->endSession((string) $token));
        }

        Log::info('ConferenceOfferService: Cancelled pending agent sessions', [
            'call_queue_id' => $callQueue->id,
            'call_id' => $callId,
            'agent_user_ids' => array_keys($offered),
        ]);
    }

    /**
     * The caller was served the room join: from here a winner hang-up is an
     * ordinary end of call (endConferenceOnExit), not a failed pick-up.
     */
    public function markCallerJoined(string $callId): void
    {
        Redis::setex(self::JOINED_PREFIX.$callId, self::WINNER_TTL_SECONDS, '1');
    }

    /**
     * A session ended. If it is the winning agent leg and the caller has not
     * joined the room yet, the pick-up failed: release the winner, undo the
     * bridge and let the next poll re-offer the call.
     */
    public function releaseWinnerIfUnjoined(int $organizationId, string $sessionToken): bool
    {
        $callId = Redis::get(self::LEG_PREFIX.$sessionToken);

        if (! $callId || Redis::exists(self::JOINED_PREFIX.$callId)) {
            return false;
        }

        // Exactly one releaser (duplicate deleted/completed updates race here).
        if (Redis::del(self::LEG_PREFIX.$sessionToken) !== 1) {
            return false;
        }

        $agentUserId = $this->winnerOf($callId);
        Redis::del(self::WINNER_PREFIX.$callId, self::LOCK_PREFIX.$callId);

        $queueCall = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('call_id', $callId)
            ->whereNull('disposition')
            ->first();

        if (! $queueCall || $agentUserId === null) {
            return false;
        }

        Log::info('ConferenceOfferService: winner leg ended before the caller joined, re-offering', [
            'call_id' => $callId,
            'agent_user_id' => $agentUserId,
        ]);

        return $this->lifecycle->revertConferenceBridge($queueCall, $agentUserId);
    }

    /**
     * Room shared by the caller and the winning agent (letters/digits only).
     */
    public function roomName(QueueCall $queueCall): string
    {
        return 'q'.$queueCall->call_queue_id.'c'.preg_replace('/[^A-Za-z0-9]/', '', $queueCall->call_id);
    }

    /**
     * HMAC-signed, self-validating URL: originated and application-switched
     * fetches may not carry the voice Bearer auth.
     */
    public function signedUrl(string $routeName, CallQueue $callQueue, QueueCall $queueCall, ?int $agentUserId = null): string
    {
        $payload = json_encode(array_filter([
            'call_queue_id' => $callQueue->id,
            'call_id' => $queueCall->call_id,
            'organization_id' => $callQueue->organization_id,
            'agent_user_id' => $agentUserId,
            'exp' => now()->addSeconds($callQueue->agent_ring_timeout + self::SIGNATURE_GRACE_SECONDS)->getTimestamp(),
        ], fn ($value) => $value !== null));

        $baseUrl = rtrim($this->settings($callQueue)?->effective_webhook_base_url ?? config('app.url'), '/');

        return $baseUrl.route($routeName, [
            'sd' => base64_encode($payload),
            'sig' => $this->signature($payload),
        ], false);
    }

    /**
     * Decode and verify a signed URL's payload; null when invalid or expired.
     *
     * @return array<string, mixed>|null
     */
    public function verifiedContext(string $encoded, string $signature): ?array
    {
        $payload = base64_decode($encoded, true);

        if (! is_string($payload) || ! hash_equals($this->signature($payload), $signature)) {
            return null;
        }

        $context = json_decode($payload, true);

        if (! is_array($context) || ($context['exp'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return $context;
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function client(CallQueue $callQueue): ?CloudonixCallsClient
    {
        try {
            return new CloudonixCallsClient($this->settings($callQueue));
        } catch (\RuntimeException $e) {
            Log::warning('ConferenceOfferService: Cloudonix not configured', [
                'call_queue_id' => $callQueue->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cloudonix REST failures must never surface as a 500 to a voice webhook:
     * a failed leg is simply not offered / not torn down (logged).
     */
    private function safely(callable $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            Log::warning('ConferenceOfferService: Cloudonix REST call failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function settings(CallQueue $callQueue): ?CloudonixSettings
    {
        return CloudonixSettings::forOrganization($callQueue->organization_id);
    }
}
