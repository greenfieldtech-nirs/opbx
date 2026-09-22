<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use Illuminate\Support\Facades\Redis;

/**
 * Tracks extension call presence in Redis from Cloudonix session updates.
 *
 * Key: acd:presence:{organization_id}:{extension_number}
 * Value: "active" while the extension has a live call leg, absent otherwise.
 * Keys carry a TTL as a self-healing guard against missed terminal events.
 *
 * Used by the queue poll path to avoid offering calls to extensions already
 * on a call (e.g. a direct extension call that the ACD worker cannot see).
 */
class ExtensionPresenceTracker
{
    private const KEY_PREFIX = 'acd:presence:';

    private const TTL_SECONDS = 6 * 60 * 60;

    private const ACTIVE_STATUSES = ['answered', 'active', 'connected', 'connect', 'ringing', 'ring', 'progress'];

    private const TERMINAL_STATUSES = [
        'cancel', 'cancelled', 'canceled', 'failed', 'fail', 'error',
        'congestion', 'congested', 'busy', 'completed', 'hangup', 'bye',
    ];

    public function handleSessionUpdate(int $organizationId, ?string $destination, string $status): void
    {
        if (! $destination) {
            return;
        }

        if (in_array($status, self::ACTIVE_STATUSES, true)) {
            Redis::setex(self::key($organizationId, $destination), self::TTL_SECONDS, 'active');

            return;
        }

        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            Redis::del(self::key($organizationId, $destination));
        }
    }

    public function isBusy(int $organizationId, string $extensionNumber): bool
    {
        return Redis::get(self::key($organizationId, $extensionNumber)) === 'active';
    }

    private static function key(int $organizationId, string $extensionNumber): string
    {
        return self::KEY_PREFIX.$organizationId.':'.$extensionNumber;
    }
}
