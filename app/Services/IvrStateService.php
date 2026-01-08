<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IvrMenu;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing IVR call state in Redis.
 * Handles call tracking, turn counting, and idempotency.
 */
class IvrStateService
{
    private const CALL_STATE_TTL = 3600; // 1 hour
    private const IDEMPOTENCY_TTL = 300; // 5 minutes

    /**
     * Initialize or get call state for an IVR menu interaction.
     *
     * @param string $callSid
     * @param int $ivrMenuId
     * @return array Call state data
     */
    public function initializeCallState(string $callSid, int $ivrMenuId): array
    {
        $key = $this->getCallStateKey($callSid);

        $existingState = Redis::get($key);

        if ($existingState) {
            $state = json_decode($existingState, true);
            // Ensure menu_id matches (in case of call transfer)
            if ($state['menu_id'] !== $ivrMenuId) {
                $state = $this->createNewState($ivrMenuId);
                Redis::setex($key, self::CALL_STATE_TTL, json_encode($state));
            }
        } else {
            $state = $this->createNewState($ivrMenuId);
            Redis::setex($key, self::CALL_STATE_TTL, json_encode($state));
        }

        return $state;
    }

    /**
     * Get current call state.
     *
     * @param string $callSid
     * @return array|null
     */
    public function getCallState(string $callSid): ?array
    {
        $key = $this->getCallStateKey($callSid);
        $state = Redis::get($key);

        return $state ? json_decode($state, true) : null;
    }

    /**
     * Update call state with new data.
     *
     * @param string $callSid
     * @param array $updates
     * @return bool
     */
    public function updateCallState(string $callSid, array $updates): bool
    {
        $key = $this->getCallStateKey($callSid);
        $currentState = $this->getCallState($callSid);

        if (!$currentState) {
            return false;
        }

        $newState = array_merge($currentState, $updates);
        $result = Redis::setex($key, self::CALL_STATE_TTL, json_encode($newState));
        return $result === true || $result === 'OK';
    }

    /**
     * Increment turn count for a call.
     *
     * @param string $callSid
     * @return int New turn count
     */
    public function incrementTurnCount(string $callSid): int
    {
        $state = $this->getCallState($callSid);

        if (!$state) {
            Log::warning('IVR State: Attempted to increment turn count for non-existent call state', [
                'call_sid' => $callSid
            ]);
            return 0;
        }

        $newTurnCount = ($state['turn_count'] ?? 0) + 1;

        $this->updateCallState($callSid, [
            'turn_count' => $newTurnCount,
            'last_input_at' => now()->toISOString(),
        ]);

        return $newTurnCount;
    }

    /**
     * Check if maximum turns have been exceeded.
     *
     * @param string $callSid
     * @param int $maxTurns
     * @return bool
     */
    public function isMaxTurnsExceeded(string $callSid, int $maxTurns): bool
    {
        $state = $this->getCallState($callSid);

        if (!$state) {
            return false;
        }

        return ($state['turn_count'] ?? 0) >= $maxTurns;
    }

    /**
     * Check if a webhook event has already been processed (idempotency).
     *
     * @param string $eventId Unique event identifier
     * @return bool True if already processed
     */
    public function isEventProcessed(string $eventId): bool
    {
        $key = $this->getIdempotencyKey($eventId);
        return (bool) Redis::exists($key);
    }

    /**
     * Mark a webhook event as processed.
     *
     * @param string $eventId
     * @return bool
     */
    public function markEventProcessed(string $eventId): bool
    {
        $key = $this->getIdempotencyKey($eventId);
        $result = Redis::setex($key, self::IDEMPOTENCY_TTL, 'processed');
        return $result === true || $result === 'OK';
    }

    /**
     * Clean up call state (call ended or transferred).
     *
     * @param string $callSid
     * @return bool
     */
    public function cleanupCallState(string $callSid): bool
    {
        $key = $this->getCallStateKey($callSid);
        return Redis::del($key) > 0;
    }

    /**
     * Get statistics for monitoring.
     *
     * @return array
     */
    public function getStats(): array
    {
        // This could be implemented to track active calls, completion rates, etc.
        return [
            'active_call_states' => $this->countKeys('ivr:call:*'),
            'recent_events' => $this->countKeys('ivr:idempotency:*'),
        ];
    }

    /**
     * Create a new call state structure.
     *
     * @param int $ivrMenuId
     * @return array
     */
    private function createNewState(int $ivrMenuId): array
    {
        return [
            'menu_id' => $ivrMenuId,
            'turn_count' => 0,
            'started_at' => now()->toISOString(),
            'last_input_at' => null,
            'input_history' => [],
        ];
    }

    /**
     * Get Redis key for call state.
     *
     * @param string $callSid
     * @return string
     */
    private function getCallStateKey(string $callSid): string
    {
        return "ivr:call:{$callSid}";
    }

    /**
     * Get Redis key for idempotency.
     *
     * @param string $eventId
     * @return string
     */
    private function getIdempotencyKey(string $eventId): string
    {
        return "ivr:idempotency:{$eventId}";
    }

    /**
     * Count keys matching a pattern (approximate).
     *
     * @param string $pattern
     * @return int
     */
    private function countKeys(string $pattern): int
    {
        // Note: This is an approximation since Redis SCAN is not synchronous
        // In production, you might want to use a more sophisticated approach
        try {
            $keys = Redis::keys($pattern);
            return count($keys);
        } catch (\Exception $e) {
            Log::warning('IVR State: Failed to count keys', [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}