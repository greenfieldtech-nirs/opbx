<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use Illuminate\Support\Facades\Redis;

/**
 * Tracks extension call presence in Redis from Cloudonix session updates.
 *
 * Key: acd:presence:{organization_id}:{extension_number}
 * Value: "active|{session_modified_at_ms}" while the extension has a live call
 * leg, "idle|{ms}" after a terminal update (kept so a late, older update cannot
 * resurrect the call), absent otherwise. Cloudonix does not guarantee delivery
 * order, so an update older than the stored one is ignored. Keys carry a TTL
 * (refreshed on every update) as a self-healing guard against missed terminal events.
 *
 * Used by the queue poll path to avoid offering calls to extensions already
 * on a call (e.g. a direct extension call that the ACD worker cannot see).
 */
class ExtensionPresenceTracker
{
    private const KEY_PREFIX = 'acd:presence:';

    private const TTL_SECONDS = 60 * 60;

    // KEYS[1] presence key. ARGV: state, session_modified_at ms, ttl. Returns 1 when written, 0 when stale.
    private const SET_IF_NEWER_LUA = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        if current then
            local stored = tonumber(string.match(current, '|(%d+)$'))
            if stored and tonumber(ARGV[2]) < stored then return 0 end
        end
        redis.call('SETEX', KEYS[1], ARGV[3], ARGV[1] .. '|' .. ARGV[2])
        return 1
        LUA;

    private const ACTIVE_STATUSES = ['answered', 'active', 'connected', 'connect', 'ringing', 'ring', 'progress'];

    private const TERMINAL_STATUSES = [
        'cancel', 'cancelled', 'canceled', 'failed', 'fail', 'error',
        'congestion', 'congested', 'busy', 'completed', 'hangup', 'bye',
    ];

    /** Whether a session update means the session is over (terminal status or deletion). */
    public static function endsSession(string $status, ?string $action = null): bool
    {
        return $action === 'deleted' || in_array(strtolower($status), self::TERMINAL_STATUSES, true);
    }

    /**
     * @param  int|null  $modifiedAtMs  the session's modifiedAt (epoch ms); receipt time when unknown
     */
    public function handleSessionUpdate(int $organizationId, ?string $destination, string $status, ?string $action = null, ?int $modifiedAtMs = null): void
    {
        if (! $destination) {
            return;
        }

        // A deleted session has ended whatever its last status was: Cloudonix
        // sends the deletion with the final status (e.g. 'connected'), which
        // would otherwise leave the destination "busy" for the whole TTL.
        $state = match (true) {
            self::endsSession($status, $action) => 'idle',
            in_array(strtolower($status), self::ACTIVE_STATUSES, true) => 'active',
            default => null,
        };

        if ($state === null) {
            return;
        }

        Redis::eval(
            self::SET_IF_NEWER_LUA,
            1,
            self::key($organizationId, $destination),
            $state,
            (string) ($modifiedAtMs ?? (int) (microtime(true) * 1000)),
            (string) self::TTL_SECONDS,
        );
    }

    public function isBusy(int $organizationId, string $extensionNumber): bool
    {
        return str_starts_with((string) Redis::get(self::key($organizationId, $extensionNumber)), 'active');
    }

    private static function key(int $organizationId, string $extensionNumber): string
    {
        return self::KEY_PREFIX.$organizationId.':'.$extensionNumber;
    }
}
