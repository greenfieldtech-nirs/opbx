<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\SessionUpdate;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\QueueCallLifecycleService;
use App\Services\CloudonixClient\CloudonixCallsClient;
use App\Services\CloudonixSettingsResolver;
use App\Services\ConferenceLegLinks;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Blind transfer of a Web Phone call to an extension or an external number.
 *
 * The Web Phone identifies its call by its SIP Call-ID, which Cloudonix lists in the session's
 * call_ids (the caller's leg first). The party that stays on the line is sent to a signed CXML URL
 * that routes the destination exactly as if the transferring extension had dialled it, so extension
 * routing (voicemail, forwarding) and the outbound whitelist and caller ID rules all apply.
 *
 * - Conference-bridged call (simultaneous ring group, queue conference): the caller's own session
 *   is switched out of the room.
 * - The Web Phone placed the call: the session is forked; the Web Phone's side hangs up and the
 *   called party goes to the destination.
 * - The Web Phone was dialled inside the caller's session: that session is switched.
 *
 * The Web Phone hangs up its own leg once the transfer succeeds.
 */
final class CallTransferService
{
    private const SIGNATURE_VALID_SECONDS = 120;

    // A session row older than this is not a call the Web Phone can still be on.
    private const LIVE_WINDOW_HOURS = 4;

    public function __construct(
        private readonly ConferenceLegLinks $legLinks,
        private readonly QueueCallLifecycleService $queueLifecycle,
    ) {}

    /**
     * The session update proving that the extension's own leg is this SIP Call-ID; null otherwise.
     * Every organization member can read live calls' call_ids, so the Call-ID alone does not prove
     * the call is the user's. Accepted proof, strongest first:
     *
     * - the session carries the extension's own Cloudonix subscriber (a caller ID can be spoofed by
     *   an outside caller, the subscriber cannot) and the Call-ID is either its first leg (the
     *   extension placed the call) or its newest leg (Cloudonix dialled the extension, e.g. a DID
     *   routed to it: the session's subscriber follows the last subscriber dialled, so a ring
     *   group member rung earlier cannot claim it);
     * - the extension was dialled: a leg carrying the Call-ID has the extension as its destination.
     */
    public function findSession(Extension $extension, string $callId): ?SessionUpdate
    {
        $rows = SessionUpdate::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $extension->organization_id)
            ->whereJsonContains('call_ids', $callId)
            ->whereNotNull('session_token')
            ->where('created_at', '>=', now()->subHours(self::LIVE_WINDOW_HOURS))
            ->latest('id')
            ->limit(100)
            ->get();

        $subscriberId = (string) $extension->cloudonix_subscriber_id;

        // Each row is a snapshot: test the subscriber against each session's current (newest) row
        // only, or an older snapshot from when this extension was the last one dialled would
        // still match after Cloudonix rang, and connected, someone else.
        $current = $rows->isEmpty() ? collect() : SessionUpdate::withoutGlobalScope(OrganizationScope::class)
            ->whereIn('id', fn ($query) => $query->selectRaw('max(id)')
                ->from('session_updates')
                ->where('organization_id', $extension->organization_id)
                ->whereIn('session_token', $rows->pluck('session_token')->unique())
                // The synthetic CDR row written after hangup is not a session snapshot.
                ->where(fn ($q) => $q->whereNull('action')->orWhere('action', '!=', 'cdr_final_status'))
                ->groupBy('session_token'))
            ->latest('id')
            ->get();

        return $current->first(fn (SessionUpdate $row) => $subscriberId !== ''
                && (string) $row->subscriber_id === $subscriberId
                && in_array($callId, [Arr::first($row->call_ids ?? []), Arr::last($row->call_ids ?? [])], true))
            ?? $rows->first(fn (SessionUpdate $row) => ltrim((string) $row->destination, '+') === (string) $extension->extension_number);
    }

    /**
     * Whether the proven row says the Web Phone placed the call (its Call-ID is the caller leg).
     */
    private function placedByWebPhone(SessionUpdate $session, string $callId, Extension $from): bool
    {
        return (string) $from->cloudonix_subscriber_id !== ''
            && ($session->call_ids[0] ?? null) === $callId
            && (string) $session->subscriber_id === (string) $from->cloudonix_subscriber_id;
    }

    public function blindTransfer(SessionUpdate $session, string $callId, Extension $from, string $destination): bool
    {
        $organizationId = (int) $session->organization_id;
        $token = (string) $session->session_token;

        try {
            $client = new CloudonixCallsClient(CloudonixSettings::forOrganization($organizationId));
        } catch (\RuntimeException $e) {
            Log::warning('CallTransferService: Cloudonix not configured', ['organization_id' => $organizationId]);

            return false;
        }

        $url = $this->signedUrl($organizationId, (string) $from->extension_number, $destination);
        $callerToken = $this->legLinks->callersOf([$token])[$token] ?? null;

        $mode = match (true) {
            $callerToken !== null => 'conference',
            $this->placedByWebPhone($session, $callId, $from) => 'fork',
            default => 'switch',
        };

        try {
            $transferred = match ($mode) {
                'conference' => $client->switchVoiceApplication($callerToken, $url),
                // Live-verified: Cloudonix runs `callee` on the Web Phone's own leg, `caller` on the far end.
                'fork' => $client->forkCall($token, ['url' => $url, 'method' => 'POST'], ['cxml' => CxmlBuilder::simpleHangup()]),
                'switch' => $client->switchVoiceApplication($token, $url),
            };
        } catch (\Throwable $e) {
            Log::warning('CallTransferService: Cloudonix REST call failed', ['mode' => $mode, 'error' => $e->getMessage()]);
            $transferred = false;
        }

        Log::info('CallTransferService: Blind transfer', [
            'organization_id' => $organizationId,
            'session_token' => $token,
            'caller_token' => $callerToken,
            'mode' => $mode,
            'from_extension' => $from->extension_number,
            'destination' => $destination,
            'transferred' => $transferred,
        ]);

        if ($transferred && $from->user_id !== null) {
            $this->queueLifecycle->finalizeTransferred($organizationId, $callerToken ?? $token, (int) $from->user_id, $destination);
        }

        return $transferred;
    }

    /**
     * Decode and verify a transfer URL's payload; null when invalid or expired.
     *
     * @return array{organization_id: int, from: string, to: string, exp: int}|null
     */
    public function verifiedContext(string $encoded, string $signature): ?array
    {
        $payload = base64_decode($encoded, true);

        if (! is_string($payload) || ! hash_equals($this->signature($payload), $signature)) {
            return null;
        }

        $context = json_decode($payload, true);

        return is_array($context) && ($context['exp'] ?? 0) >= now()->getTimestamp() ? $context : null;
    }

    /**
     * HMAC-signed, self-validating URL: application-switched and forked fetches may not carry the
     * voice Bearer auth.
     */
    private function signedUrl(int $organizationId, string $from, string $to): string
    {
        $payload = json_encode([
            'organization_id' => $organizationId,
            'from' => $from,
            'to' => $to,
            'exp' => now()->addSeconds(self::SIGNATURE_VALID_SECONDS)->getTimestamp(),
        ]);

        return app(CloudonixSettingsResolver::class)->webhookBaseUrl($organizationId)
            .route('voice.transfer', ['sd' => base64_encode($payload), 'sig' => $this->signature($payload)], false);
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', 'transfer:'.$payload, (string) config('app.key'));
    }
}
