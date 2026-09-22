<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use App\Models\CallQueue;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
use App\Services\CloudonixClient\CloudonixCallsClient;
use Illuminate\Support\Facades\Log;

/**
 * Immediate connect: when an agent becomes available, interrupt the hold of
 * waiting callers via the Cloudonix REST application switch — no waiting for
 * the next poll cycle.
 *
 * The worker remains the single source of truth: every offer re-validates via
 * poll(), and the signed queue-dial endpoint re-validates again when
 * Cloudonix fetches the new CXML. Any failure leaves the pull path intact.
 */
class ImmediateConnectService
{
    private const SIGNATURE_TTL_SECONDS = 120;

    public function __construct(
        private readonly AcdWorkerClient $worker,
        private readonly QueueDialOfferService $dialOffer,
    ) {}

    /**
     * Triggered when an agent of this queue becomes available.
     */
    public function connectAvailableCallers(CallQueue $callQueue): void
    {
        if (! $callQueue->immediate_connect || ! $callQueue->isActive()) {
            return;
        }

        $roster = $this->dialOffer->roster($callQueue);
        $live = $this->worker->live($callQueue->organization_id, $callQueue->id, $roster);

        // Reap ghost entries (calls that ended without the worker being told)
        // so a stale head cannot block immediate connect.
        app(QueueCallLifecycleService::class)->reconcileStaleCalls(
            $callQueue,
            collect($live['waiting'] ?? [])->pluck('callId')->all()
        );

        $live = $this->worker->live($callQueue->organization_id, $callQueue->id, $roster);

        // FIFO: only the head caller can be offered (worker semantics serialize
        // offers naturally — subsequent polls return wait for non-head calls).
        $head = collect($live['waiting'] ?? [])->sortBy('position')->first();
        if (! $head) {
            return;
        }

        $queueCall = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->where('call_id', $head['callId'])
            ->whereNull('disposition')
            ->first();

        if (! $queueCall || ! $queueCall->session_token) {
            Log::info('ImmediateConnectService: head caller has no session token, skipping', [
                'call_queue_id' => $callQueue->id,
                'call_id' => $head['callId'] ?? null,
            ]);

            return;
        }

        // A caller past max wait must overflow (pull path), not be switched.
        $waitedSeconds = max(0, (int) $queueCall->entered_at->diffInSeconds(now()));
        if ($waitedSeconds >= $callQueue->max_wait_seconds) {
            return;
        }

        $result = $this->worker->poll(
            $callQueue->organization_id,
            $callQueue->id,
            $queueCall->call_id,
            $roster,
            $callQueue->strategy->value,
            $callQueue->max_wait_seconds
        );

        if (($result['action'] ?? 'wait') !== 'dial') {
            return;
        }

        $this->switchApplication($callQueue, $queueCall);
    }

    private function switchApplication(CallQueue $callQueue, QueueCall $queueCall): void
    {
        $dialUrl = $this->signedDialUrl($callQueue, $queueCall);

        $settings = \App\Models\CloudonixSettings::where('organization_id', $callQueue->organization_id)->first();
        $client = new CloudonixCallsClient($settings);

        if ($client->switchVoiceApplication($queueCall->session_token, $dialUrl)) {
            Log::info('ImmediateConnectService: switched waiting caller to dial application', [
                'call_queue_id' => $callQueue->id,
                'call_queue_name' => $callQueue->name,
                'call_id' => $queueCall->call_id,
                'session_token' => $queueCall->session_token,
            ]);
        } else {
            Log::warning('ImmediateConnectService: application switch failed, pull path remains', [
                'call_queue_id' => $callQueue->id,
                'call_id' => $queueCall->call_id,
            ]);
        }
    }

    /**
     * HMAC-signed URL for the proactive dial endpoint. Self-validating (no
     * dependence on Cloudonix sending voice auth on application-switched
     * fetches); short-lived.
     */
    public function signedDialUrl(CallQueue $callQueue, QueueCall $queueCall): string
    {
        $payload = json_encode([
            'call_queue_id' => $callQueue->id,
            'call_id' => $queueCall->call_id,
            'organization_id' => $callQueue->organization_id,
            'exp' => now()->addSeconds(self::SIGNATURE_TTL_SECONDS)->getTimestamp(),
        ]);

        $cloudonixSettings = \App\Models\CloudonixSettings::where('organization_id', $callQueue->organization_id)->first();
        $baseUrl = rtrim($cloudonixSettings?->effective_webhook_base_url ?? config('app.url'), '/');

        return $baseUrl.route('voice.queue-dial', [
            'sd' => base64_encode($payload),
            'sig' => $this->signature($payload),
        ], false);
    }

    public function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    public function verifySignature(string $payload, string $signature): bool
    {
        return hash_equals($this->signature($payload), $signature);
    }
}
