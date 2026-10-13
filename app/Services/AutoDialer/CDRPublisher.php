<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Auto-dialer CDR side effects. The former `cdr:completed` Redis publish was removed:
 * nothing subscribed to it (the Go worker never did).
 */
class CDRPublisher
{
    /**
     * Decrement the CAC (Concurrent Active Calls) counter once per session.
     *
     * Uses the 'dialer' connection (no key prefix) because the Go worker writes raw
     * keys like dialer:cac:{id}:active. A SET NX marker makes a repeated CDR/proxy
     * webhook a no-op, and the decrement is clamped at 0 atomically so it can never
     * overwrite a concurrent worker INCR.
     */
    public function decrementCacCounter(int $campaignId, string $sessionToken): void
    {
        $marker = "dialer:cac:decremented:{$sessionToken}";
        $redis = null;

        try {
            $redis = Redis::connection('dialer');

            if (! $redis->set($marker, '1', 'EX', 86400, 'NX')) {
                Log::info('CAC decrement already processed for session', [
                    'campaign_id' => $campaignId,
                    'session_token' => $sessionToken,
                ]);

                return;
            }

            $newCount = (int) $redis->eval(
                "local n = tonumber(redis.call('GET', KEYS[1]) or '0'); if n > 0 then return redis.call('DECR', KEYS[1]) end; return 0",
                1,
                "dialer:cac:{$campaignId}:active"
            );

            Log::info('CAC counter decremented', [
                'campaign_id' => $campaignId,
                'active_calls' => $newCount,
                'session_token' => $sessionToken,
            ]);
        } catch (\Exception $e) {
            // Release the claim so a retried webhook can still decrement.
            try {
                $redis?->del($marker);
            } catch (\Exception) {
                // best effort
            }

            Log::error('Failed to decrement CAC counter', [
                'campaign_id' => $campaignId,
                'session_token' => $sessionToken,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
