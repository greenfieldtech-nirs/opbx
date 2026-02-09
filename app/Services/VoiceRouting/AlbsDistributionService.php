<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\AlbsStrategy;
use App\Enums\UserStatus;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * AI Assistant Load Balancer Distribution Service
 *
 * Implements distribution algorithms for routing calls across multiple AI assistants:
 * - Round Robin: Sequential distribution with Redis-backed counter
 * - Priority: Route to highest priority (lowest number) available assistant
 * - Percentage: Weighted random distribution based on configured percentages
 *
 * Phase 2: Core Business Logic
 */
class AlbsDistributionService
{
    /**
     * Redis key prefix for round robin counters
     */
    private const ROUND_ROBIN_KEY_PREFIX = 'albs:rr';

    /**
     * TTL for round robin counters in seconds (24 hours)
     */
    private const ROUND_ROBIN_TTL = 86400;

    /**
     * Select an AI Assistant using the load balancer's configured strategy
     *
     * @param  AiAssistantLoadBalancer  $albs  The load balancer configuration
     * @return AiAssistant|null The selected AI assistant, or null if none available
     */
    public function selectAssistant(AiAssistantLoadBalancer $albs): ?AiAssistant
    {
        $strategy = $albs->strategy;

        Log::debug('ALBS: Selecting assistant', [
            'albs_id' => $albs->id,
            'strategy' => $strategy->value,
        ]);

        return match ($strategy) {
            AlbsStrategy::ROUND_ROBIN => $this->selectUsingRoundRobin($albs),
            AlbsStrategy::PRIORITY => $this->selectUsingPriority($albs),
            AlbsStrategy::PERCENTAGE => $this->selectUsingPercentage($albs),
        };
    }

    /**
     * Round Robin Distribution Algorithm
     *
     * Distributes calls evenly across all active members in sequential order.
     * Uses Redis atomic increment for thread-safe counter management.
     *
     * Algorithm:
     * 1. Get active members ordered by position
     * 2. Atomically increment Redis counter
     * 3. Use modulo operation to select member (counter % member_count)
     * 4. Return selected AI assistant
     */
    public function selectUsingRoundRobin(AiAssistantLoadBalancer $albs): ?AiAssistant
    {
        // Get active members ordered by position
        $members = $this->getActiveMembersOrdered($albs, 'position');

        if ($members->isEmpty()) {
            Log::warning('ALBS Round Robin: No active members available', [
                'albs_id' => $albs->id,
            ]);

            return null;
        }

        $memberCount = $members->count();

        try {
            // Use Redis for atomic counter increment
            $key = $this->getRoundRobinKey($albs->id);
            $counter = Redis::incr($key);

            // Set expiry on first increment to auto-reset after 24 hours
            if ($counter === 1) {
                Redis::expire($key, self::ROUND_ROBIN_TTL);
            }

            // Calculate index using modulo (counter - 1 to make it 0-indexed)
            $index = ($counter - 1) % $memberCount;

            $selectedMember = $members[$index];

            Log::debug('ALBS Round Robin: Selected assistant', [
                'albs_id' => $albs->id,
                'counter' => $counter,
                'index' => $index,
                'member_count' => $memberCount,
                'selected_assistant_id' => $selectedMember->ai_assistant_id,
            ]);

            return $selectedMember->aiAssistant;

        } catch (\Exception $e) {
            Log::error('ALBS Round Robin: Redis error, falling back to random selection', [
                'albs_id' => $albs->id,
                'error' => $e->getMessage(),
            ]);

            // Fallback: random selection if Redis fails
            return $members->random()?->aiAssistant;
        }
    }

    /**
     * Priority-Based Distribution Algorithm
     *
     * Always routes to the highest priority (lowest priority number) available AI assistant.
     * Lower number = higher priority (0 is highest priority).
     *
     * Algorithm:
     * 1. Get active members ordered by priority ascending
     * 2. Return the first member (highest priority)
     */
    public function selectUsingPriority(AiAssistantLoadBalancer $albs): ?AiAssistant
    {
        // Get active members ordered by priority (ascending - 0 is highest)
        $members = $this->getActiveMembersOrdered($albs, 'priority');

        if ($members->isEmpty()) {
            Log::warning('ALBS Priority: No active members available', [
                'albs_id' => $albs->id,
            ]);

            return null;
        }

        // Select first member (highest priority - lowest number)
        $selectedMember = $members->first();

        Log::debug('ALBS Priority: Selected assistant', [
            'albs_id' => $albs->id,
            'priority' => $selectedMember->priority,
            'selected_assistant_id' => $selectedMember->ai_assistant_id,
        ]);

        return $selectedMember->aiAssistant;
    }

    /**
     * Percentage-Based (Weighted Random) Distribution Algorithm
     *
     * Distributes calls according to configured weight percentages.
     * Uses weighted random selection with automatic normalization.
     *
     * Algorithm:
     * 1. Get active members with their weights
     * 2. Calculate total weight (handles non-100% sums)
     * 3. Generate random number between 1 and total weight
     * 4. Select member based on cumulative weight ranges
     * 5. Return selected AI assistant
     *
     * Example with weights [60, 40]:
     * - Random 1-60 → selects first (60% probability)
     * - Random 61-100 → selects second (40% probability)
     */
    public function selectUsingPercentage(AiAssistantLoadBalancer $albs): ?AiAssistant
    {
        // Get active members
        $members = $this->getActiveMembers($albs);

        if ($members->isEmpty()) {
            Log::warning('ALBS Percentage: No active members available', [
                'albs_id' => $albs->id,
            ]);

            return null;
        }

        // Calculate total weight (handles cases where weights don't sum to 100)
        $totalWeight = $members->sum('weight');

        if ($totalWeight <= 0) {
            Log::warning('ALBS Percentage: All weights are zero, using random fallback', [
                'albs_id' => $albs->id,
            ]);

            // All weights are 0, use random selection
            return $members->random()?->aiAssistant;
        }

        // Generate random number between 1 and total weight
        $random = random_int(1, $totalWeight);

        // Select member based on cumulative weight
        $cumulative = 0;
        $selectedMember = null;

        foreach ($members as $member) {
            $cumulative += $member->weight;
            if ($random <= $cumulative) {
                $selectedMember = $member;
                break;
            }
        }

        // Fallback (should rarely reach here due to random range)
        if (! $selectedMember) {
            $selectedMember = $members->first();
        }

        Log::debug('ALBS Percentage: Selected assistant', [
            'albs_id' => $albs->id,
            'random' => $random,
            'total_weight' => $totalWeight,
            'selected_weight' => $selectedMember->weight,
            'selected_assistant_id' => $selectedMember->ai_assistant_id,
        ]);

        return $selectedMember->aiAssistant;
    }

    /**
     * Get active members for a load balancer
     *
     * Returns members that are:
     * - Status is 'active'
     * - Have an active AI assistant
     */
    public function getActiveMembers(AiAssistantLoadBalancer $albs): Collection
    {
        return $albs->members()
            ->where('status', 'active')
            ->whereHas('aiAssistant', function ($query) {
                $query->withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                    ->where('status', UserStatus::ACTIVE->value);
            })
            ->with('aiAssistant')
            ->get();
    }

    /**
     * Get active members ordered by a specific column
     *
     * @param  string  $orderBy  Column to order by
     * @param  string  $direction  Sort direction (asc or desc)
     */
    public function getActiveMembersOrdered(
        AiAssistantLoadBalancer $albs,
        string $orderBy = 'position',
        string $direction = 'asc'
    ): Collection {
        return $albs->members()
            ->where('status', 'active')
            ->whereHas('aiAssistant', function ($query) {
                $query->withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                    ->where('status', UserStatus::ACTIVE->value);
            })
            ->with('aiAssistant')
            ->orderBy($orderBy, $direction)
            ->get();
    }

    /**
     * Check if load balancer has any active members available
     */
    public function hasActiveMembers(AiAssistantLoadBalancer $albs): bool
    {
        return $this->getActiveMembers($albs)->isNotEmpty();
    }

    /**
     * Get count of active members
     */
    public function getActiveMemberCount(AiAssistantLoadBalancer $albs): int
    {
        return $this->getActiveMembers($albs)->count();
    }

    /**
     * Reset the round robin counter for a load balancer
     *
     * Useful for testing or when you want to restart distribution from first member
     */
    public function resetRoundRobinCounter(int $albsId): bool
    {
        try {
            $key = $this->getRoundRobinKey($albsId);
            Redis::del($key);

            Log::info('ALBS: Round robin counter reset', [
                'albs_id' => $albsId,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('ALBS: Failed to reset round robin counter', [
                'albs_id' => $albsId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get current round robin counter value
     */
    public function getRoundRobinCounter(int $albsId): int
    {
        try {
            $key = $this->getRoundRobinKey($albsId);
            $value = Redis::get($key);

            return $value ? (int) $value : 0;
        } catch (\Exception $e) {
            Log::error('ALBS: Failed to get round robin counter', [
                'albs_id' => $albsId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Build Redis key for round robin counter
     */
    private function getRoundRobinKey(int $albsId): string
    {
        return sprintf('%s:%d', self::ROUND_ROBIN_KEY_PREFIX, $albsId);
    }

    /**
     * Validate that percentage weights sum to approximately 100%
     *
     * Returns normalized weights if they don't sum to 100
     *
     * @return array<int, float> Array of member IDs => normalized percentage
     */
    public function normalizePercentageWeights(Collection $members): array
    {
        $totalWeight = $members->sum('weight');

        if ($totalWeight === 0) {
            // Equal distribution if all weights are 0
            $equalWeight = 100 / $members->count();

            return $members->mapWithKeys(function ($member) use ($equalWeight) {
                return [$member->id => $equalWeight];
            })->toArray();
        }

        // Normalize to percentages
        return $members->mapWithKeys(function ($member) use ($totalWeight) {
            $percentage = ($member->weight / $totalWeight) * 100;

            return [$member->id => round($percentage, 2)];
        })->toArray();
    }
}
