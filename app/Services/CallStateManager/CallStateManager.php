<?php

declare(strict_types=1);

namespace App\Services\CallStateManager;

use App\Enums\CallStatus;
use App\Models\CallLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Manages call state transitions with Redis-backed locking and caching.
 */
class CallStateManager
{
    private int $lockTimeout;
    private int $stateTtl;

    public function __construct()
    {
        $this->lockTimeout = config('cloudonix.call_state.lock_timeout', 30);
        $this->stateTtl = config('cloudonix.call_state.state_ttl', 3600);
    }

    /**
     * Acquire a distributed lock for a call.
     *
     * @param string $callId
     * @return bool True if lock was acquired
     */
    public function acquireLock(string $callId): bool
    {
        $lockKey = $this->getLockKey($callId);

        try {
            return Cache::lock($lockKey, $this->lockTimeout)->get();
        } catch (\Exception $e) {
            Log::error('Failed to acquire call lock', [
                'call_id' => $callId,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Release the distributed lock for a call.
     */
    public function releaseLock(string $callId): void
    {
        $lockKey = $this->getLockKey($callId);

        try {
            Cache::lock($lockKey)->forceRelease();
        } catch (\Exception $e) {
            Log::error('Failed to release call lock', [
                'call_id' => $callId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Execute a callback with a call lock.
     *
     * @template T
     * @param string $callId
     * @param callable(): T $callback
     * @return T|null
     */
    public function withLock(string $callId, callable $callback): mixed
    {
        $lockKey = $this->getLockKey($callId);

        try {
            return Cache::lock($lockKey, $this->lockTimeout)->block($this->lockTimeout, $callback);
        } catch (\Exception $e) {
            Log::error('Failed to execute with call lock', [
                'call_id' => $callId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the current state of a call from cache.
     *
     * @return array<string, mixed>|null
     */
    public function getState(string $callId): ?array
    {
        $stateKey = $this->getStateKey($callId);

        $state = Cache::get($stateKey);

        return is_array($state) ? $state : null;
    }

    /**
     * Store the state of a call in cache.
     *
     * @param string $callId
     * @param array<string, mixed> $state
     */
    public function setState(string $callId, array $state): void
    {
        $stateKey = $this->getStateKey($callId);

        Cache::put($stateKey, $state, $this->stateTtl);

        Log::debug('Updated call state in cache', [
            'call_id' => $callId,
            'state' => $state,
        ]);
    }

    /**
     * Delete the state of a call from cache.
     */
    public function deleteState(string $callId): void
    {
        $stateKey = $this->getStateKey($callId);

        Cache::forget($stateKey);
    }

    /**
     * Transition call to a new status.
     */
    public function transitionTo(
        CallLog $callLog,
        CallStatus $newStatus,
        ?array $additionalData = null
    ): bool {
        return $this->withLock($callLog->call_id, function () use ($callLog, $newStatus, $additionalData) {
            $currentStatus = $callLog->status;

            // Validate state transition
            if (!$this->isValidTransition($currentStatus, $newStatus)) {
                Log::warning('Invalid call state transition attempted', [
                    'call_id' => $callLog->call_id,
                    'from_status' => $currentStatus->value,
                    'to_status' => $newStatus->value,
                ]);

                return false;
            }

            // Update call log
            $callLog->status = $newStatus;

            if ($additionalData) {
                foreach ($additionalData as $key => $value) {
                    if (in_array($key, $callLog->getFillable(), true)) {
                        $callLog->$key = $value;
                    }
                }
            }

            $callLog->save();

            // Update cache
            $this->setState($callLog->call_id, [
                'status' => $newStatus->value,
                'updated_at' => now()->toIso8601String(),
            ]);

            Log::info('Call state transitioned', [
                'call_id' => $callLog->call_id,
                'from_status' => $currentStatus->value,
                'to_status' => $newStatus->value,
            ]);

            return true;
        }) ?? false;
    }

    /**
     * Check if a state transition is valid.
     */
    private function isValidTransition(CallStatus $from, CallStatus $to): bool
    {
        // Terminal states cannot transition
        if ($from->isTerminal()) {
            return false;
        }

        // Define valid transitions
        $validTransitions = [
            CallStatus::INITIATED->value => [
                CallStatus::RINGING->value,
                CallStatus::FAILED->value,
                CallStatus::BUSY->value,
            ],
            CallStatus::RINGING->value => [
                CallStatus::ANSWERED->value,
                CallStatus::NO_ANSWER->value,
                CallStatus::BUSY->value,
                CallStatus::FAILED->value,
            ],
            CallStatus::ANSWERED->value => [
                CallStatus::COMPLETED->value,
                CallStatus::FAILED->value,
            ],
        ];

        $allowedNextStates = $validTransitions[$from->value] ?? [];

        return in_array($to->value, $allowedNextStates, true);
    }

    /**
     * Get the Redis key for call lock.
     */
    private function getLockKey(string $callId): string
    {
        return "lock:call:{$callId}";
    }

    /**
     * Get the Redis key for call state.
     */
    private function getStateKey(string $callId): string
    {
        return "call:state:{$callId}";
    }
}
