<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Jobs\ExpireRingGroupOfferJob;
use App\Models\CallDetailRecord;
use App\Models\CloudonixSettings;
use App\Models\RingGroup;
use App\Services\CloudonixClient\CloudonixCallsClient;
use App\Services\CloudonixSettingsResolver;
use App\Services\ConferenceLegLinks;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Simultaneous ring groups: every member rings in its OWN Cloudonix session (like the call queue's
 * "Ring Agents in Separate Calls"), so the member who answers is known.
 *
 * The caller waits alone in a conference room (Cloudonix hold music). The first member session to answer
 * claims the call (SET NX) and joins the room; the other member sessions are ended. When every member
 * session ended unanswered, or the ring timeout passed (ExpireRingGroupOfferJob), the caller is switched
 * to the signed fallback URL. The same NX key decides between "a member won", "fallback" and "caller left".
 *
 * Redis (key part {g}:{t} = ring group id : caller session token):
 *   rg:offer:{g}:{t}      hash   member extension number => member session token (still ringing)
 *   rg:resolved:{g}:{t}   string winner extension number | 'fallback' | 'gone'
 *   rg:leg:{legToken}     string "{g}|{t}|{extension}" (a member session ending unanswered)
 *   rg:caller:{t}         string {g} (the caller hanging up while members ring)
 */
class RingGroupConferenceService
{
    private const TTL_SECONDS = 2 * 60 * 60;

    // Origination latency on top of the ring timeout before the safety net runs the fallback.
    private const GRACE_SECONDS = 10;

    // Signed URLs must stay valid until the last member could answer.
    private const SIGNATURE_GRACE_SECONDS = 60;

    /**
     * Originate one session per member. Returns false when none could be started (the caller then gets the
     * fallback right away).
     *
     * @param  array<string, string>  $destinations  member extension number => what to ring (number, SIP URI)
     */
    public function offer(RingGroup $ringGroup, string $callerToken, string $callerNumber, array $destinations): bool
    {
        $timeout = $ringGroup->timeout ?? 30;
        $context = ['ring_group_id' => $ringGroup->id, 'organization_id' => $ringGroup->organization_id, 'caller_token' => $callerToken];

        $payloads = [];
        foreach ($destinations as $extension => $destination) {
            // The member sees the caller's number, as with a <Dial> (omitted for an anonymous caller).
            $payloads[(string) $extension] = array_filter([
                'destination' => $destination,
                'caller-id' => $callerNumber,
                'url' => $this->signedUrl('voice.ring-group-member-connect', $context + ['extension' => (string) $extension], $timeout),
                'timeout' => $timeout,
            ], fn ($value) => $value !== '');
        }

        $client = $this->client($ringGroup->organization_id);
        $results = $payloads === [] ? [] : ($this->safely(fn () => $client?->originateApplicationCalls($payloads)) ?? []);

        $legs = [];
        foreach ($results as $extension => $result) {
            if (! empty($result['token'])) {
                $legs[(string) $extension] = (string) $result['token'];
            }
        }

        Log::info('RingGroupConferenceService: Rang members in separate calls', [
            'ring_group_id' => $ringGroup->id,
            'caller_token' => $callerToken,
            'offered' => array_keys($payloads),
            'originated' => array_keys($legs),
        ]);

        if ($legs === []) {
            return false;
        }

        $key = $this->key($ringGroup->id, $callerToken);
        Redis::del('rg:resolved:'.$key);
        Redis::hmset('rg:offer:'.$key, $legs);
        Redis::expire('rg:offer:'.$key, self::TTL_SECONDS);
        Redis::setex('rg:caller:'.$callerToken, self::TTL_SECONDS, (string) $ringGroup->id);
        foreach ($legs as $extension => $legToken) {
            Redis::setex('rg:leg:'.$legToken, self::TTL_SECONDS, "{$ringGroup->id}|{$callerToken}|{$extension}");
        }
        app(ConferenceLegLinks::class)->link($legs, $callerToken);

        ExpireRingGroupOfferJob::dispatch($ringGroup->id, $ringGroup->organization_id, $callerToken)
            ->delay(now()->addSeconds($timeout + self::GRACE_SECONDS));

        return true;
    }

    /**
     * A member session answered. The first one of the current offer wins: the other member sessions are
     * ended and the member is recorded as the call's answering extension. Every other answer loses.
     *
     * @param  array<string, mixed>  $context  verified member-connect URL context
     */
    public function claim(array $context, string $legToken): bool
    {
        $ringGroupId = (int) $context['ring_group_id'];
        $callerToken = (string) $context['caller_token'];
        $extension = (string) $context['extension'];
        $key = $this->key($ringGroupId, $callerToken);

        $offered = Redis::hgetall('rg:offer:'.$key) ?: [];

        if (($offered[$extension] ?? null) !== $legToken
            || ! Redis::set('rg:resolved:'.$key, $extension, 'EX', self::TTL_SECONDS, 'NX')) {
            return false;
        }

        Redis::del('rg:offer:'.$key);
        $this->endSessions((int) $context['organization_id'], array_diff_key($offered, [$extension => true]));
        CallDetailRecord::recordAnsweringExtension((int) $context['organization_id'], $callerToken, $extension);

        Log::info('RingGroupConferenceService: Member answered', [
            'ring_group_id' => $ringGroupId,
            'caller_token' => $callerToken,
            'extension' => $extension,
        ]);

        return true;
    }

    /**
     * Nobody answered (every member session ended, or the ring timeout passed): end the members still
     * ringing and switch the caller to the ring group's fallback. No-op once the call is resolved.
     */
    public function fallBack(int $ringGroupId, int $organizationId, string $callerToken): void
    {
        $key = $this->key($ringGroupId, $callerToken);

        if (! Redis::set('rg:resolved:'.$key, 'fallback', 'EX', self::TTL_SECONDS, 'NX')) {
            return;
        }

        $offered = Redis::hgetall('rg:offer:'.$key) ?: [];
        Redis::del('rg:offer:'.$key);
        $this->endSessions($organizationId, $offered);

        $url = $this->signedUrl('voice.ring-group-fallback', [
            'ring_group_id' => $ringGroupId, 'organization_id' => $organizationId, 'caller_token' => $callerToken,
        ], self::SIGNATURE_GRACE_SECONDS);
        $switched = $this->safely(fn () => $this->client($organizationId)?->switchVoiceApplication($callerToken, $url));

        Log::info('RingGroupConferenceService: Nobody answered, caller sent to the fallback', [
            'ring_group_id' => $ringGroupId,
            'caller_token' => $callerToken,
            'switched' => (bool) $switched,
        ]);
    }

    /**
     * A Cloudonix session ended: a member session that ends unanswered leaves the offer (the last one runs
     * the fallback); the caller hanging up ends the members still ringing.
     */
    public function sessionEnded(int $organizationId, string $sessionToken): void
    {
        $leg = Redis::get('rg:leg:'.$sessionToken);
        if ($leg) {
            Redis::del('rg:leg:'.$sessionToken);
            [$ringGroupId, $callerToken, $extension] = explode('|', $leg, 3);
            $key = $this->key((int) $ringGroupId, $callerToken);

            if (! Redis::exists('rg:resolved:'.$key)) {
                Redis::hdel('rg:offer:'.$key, $extension);
                if (! Redis::exists('rg:offer:'.$key)) {
                    $this->fallBack((int) $ringGroupId, $organizationId, $callerToken);
                }
            }

            return;
        }

        $ringGroupId = Redis::get('rg:caller:'.$sessionToken);
        if (! $ringGroupId) {
            return;
        }
        Redis::del('rg:caller:'.$sessionToken);

        $key = $this->key((int) $ringGroupId, $sessionToken);
        if (Redis::set('rg:resolved:'.$key, 'gone', 'EX', self::TTL_SECONDS, 'NX')) {
            $offered = Redis::hgetall('rg:offer:'.$key) ?: [];
            Redis::del('rg:offer:'.$key);
            $this->endSessions($organizationId, $offered);
        }
    }

    /**
     * Room shared by the caller and the member who answers: 'r' + 15 hex chars of a hash of the group and
     * the caller's session, kept within Cloudonix's 16-character conference name limit.
     */
    public function roomName(int $ringGroupId, string $callerToken): string
    {
        return 'r'.substr(hash('sha256', $ringGroupId.':'.$callerToken), 0, 15);
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

        return is_array($context) && ($context['exp'] ?? 0) >= now()->getTimestamp() ? $context : null;
    }

    /**
     * HMAC-signed, self-validating URL: originated and application-switched fetches may not carry the
     * voice Bearer auth.
     *
     * @param  array<string, mixed>  $context
     */
    private function signedUrl(string $routeName, array $context, int $validSeconds): string
    {
        $payload = json_encode($context + ['exp' => now()->addSeconds($validSeconds + self::SIGNATURE_GRACE_SECONDS)->getTimestamp()]);

        return app(CloudonixSettingsResolver::class)->webhookBaseUrl((int) $context['organization_id'])
            .route($routeName, ['sd' => base64_encode($payload), 'sig' => $this->signature($payload)], false);
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function key(int $ringGroupId, string $callerToken): string
    {
        return $ringGroupId.':'.$callerToken;
    }

    /**
     * @param  array<string, string>  $legs  extension => member session token
     */
    private function endSessions(int $organizationId, array $legs): void
    {
        $client = $legs === [] ? null : $this->client($organizationId);

        foreach ($legs as $legToken) {
            Redis::del('rg:leg:'.$legToken);
            $this->safely(fn () => $client?->endSession((string) $legToken));
        }
    }

    private function client(int $organizationId): ?CloudonixCallsClient
    {
        try {
            return new CloudonixCallsClient(CloudonixSettings::forOrganization($organizationId));
        } catch (\RuntimeException $e) {
            Log::warning('RingGroupConferenceService: Cloudonix not configured', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cloudonix REST failures must never surface as a 500 to a voice webhook (logged instead).
     */
    private function safely(callable $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            Log::warning('RingGroupConferenceService: Cloudonix REST call failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
