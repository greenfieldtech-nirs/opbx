<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Models\BusinessHoursSchedule;
use App\Models\Extension;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Voice Routing Cache Service
 *
 * Implements cache-aside pattern for voice routing lookups to improve performance.
 * Caches frequently accessed data with appropriate TTLs and handles cache invalidation.
 *
 * Phase 1 Step 8: Redis Caching Layer
 */
class VoiceRoutingCacheService
{
    /**
     * Get TTL for extension cache entries
     */
    private function getExtensionCacheTtl(): int
    {
        return config('voice_routing.cache.extension_ttl', 1800);
    }

    /**
     * Get TTL for business hours schedule cache entries
     */
    private function getBusinessHoursCacheTtl(): int
    {
        return config('voice_routing.cache.business_hours_ttl', 900);
    }

    /**
     * Cache key prefix for extensions
     */
    private const EXTENSION_KEY_PREFIX = 'routing:extension';

    /**
     * Cache key prefix for business hours schedules
     */
    private const BUSINESS_HOURS_KEY_PREFIX = 'routing:business_hours';

    /**
     * Cache key prefix for AI assistant load balancers
     */
    private const ALBS_KEY_PREFIX = 'albs';

    /**
     * Get TTL for ALBS cache entries
     */
    private function getAlbsCacheTtl(): int
    {
        return config('voice_routing.cache.albs_ttl', 1800);
    }

    /**
     * Get extension by organization and extension number with caching
     *
     * Implements cache-aside pattern:
     * 1. Check cache first
     * 2. If miss, load from database
     * 3. Store in cache for future requests
     * 4. If cache unavailable, fallback to database
     */
    public function getExtension(int $organizationId, string $extensionNumber): ?Extension
    {
        $cacheKey = $this->buildExtensionCacheKey($organizationId, $extensionNumber);

        try {
            // Try to get from cache
            $extension = Cache::remember(
                $cacheKey,
                $this->getExtensionCacheTtl(),
                function () use ($organizationId, $extensionNumber) {
                    Log::debug('Voice routing cache: Extension cache miss', [
                        'organization_id' => $organizationId,
                        'extension_number' => $extensionNumber,
                    ]);

                    return Extension::withoutGlobalScope(OrganizationScope::class)
                        ->with([
                            'user',
                            'aiAssistant' => function ($query) use ($organizationId) {
                                $query->withoutGlobalScope(OrganizationScope::class)
                                    ->where('organization_id', $organizationId);
                            },
                        ])
                        ->where('organization_id', $organizationId)
                        ->where('extension_number', $extensionNumber)
                        ->first();
                }
            );

            return $extension;
        } catch (\Exception $e) {
            // If cache fails, fallback to direct database query
            Log::warning('Voice routing cache: Cache unavailable, falling back to database', [
                'organization_id' => $organizationId,
                'extension_number' => $extensionNumber,
                'error' => $e->getMessage(),
            ]);

            return Extension::with('user')
                ->where('organization_id', $organizationId)
                ->where('extension_number', $extensionNumber)
                ->first();
        }
    }

    /**
     * Get active business hours schedule for organization with caching
     *
     * Implements cache-aside pattern for business hours schedules.
     */
    public function getActiveBusinessHoursSchedule(int $organizationId): ?BusinessHoursSchedule
    {
        $cacheKey = $this->buildBusinessHoursCacheKey($organizationId);

        try {
            // Try to get from cache
            $schedule = Cache::remember(
                $cacheKey,
                $this->getBusinessHoursCacheTtl(),
                function () use ($organizationId) {
                    Log::debug('Voice routing cache: Business hours cache miss, loading from database', [
                        'organization_id' => $organizationId,
                    ]);

                    return BusinessHoursSchedule::where('organization_id', $organizationId)
                        ->active()
                        ->with(['scheduleDays.timeRanges', 'exceptions.timeRanges'])
                        ->first();
                }
            );

            if ($schedule) {
                Log::debug('Voice routing cache: Business hours schedule retrieved', [
                    'organization_id' => $organizationId,
                    'schedule_id' => $schedule->id,
                    'from_cache' => Cache::has($cacheKey),
                ]);
            }

            return $schedule;
        } catch (\Exception $e) {
            // If cache fails, fallback to direct database query
            Log::warning('Voice routing cache: Cache unavailable, falling back to database', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return BusinessHoursSchedule::where('organization_id', $organizationId)
                ->active()
                ->with(['scheduleDays.timeRanges', 'exceptions.timeRanges'])
                ->first();
        }
    }

    /**
     * Invalidate extension cache
     *
     * Called when an extension is updated to ensure cache consistency.
     */
    public function invalidateExtension(int $organizationId, string $extensionNumber): void
    {
        $cacheKey = $this->buildExtensionCacheKey($organizationId, $extensionNumber);

        try {
            Cache::forget($cacheKey);

            Log::debug('Voice routing cache: Extension cache invalidated', [
                'organization_id' => $organizationId,
                'extension_number' => $extensionNumber,
                'cache_key' => $cacheKey,
            ]);
        } catch (\Exception $e) {
            Log::warning('Voice routing cache: Failed to invalidate extension cache', [
                'organization_id' => $organizationId,
                'extension_number' => $extensionNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate business hours schedule cache
     *
     * Called when a business hours schedule is updated to ensure cache consistency.
     */
    public function invalidateBusinessHoursSchedule(int $organizationId): void
    {
        $cacheKey = $this->buildBusinessHoursCacheKey($organizationId);

        try {
            Cache::forget($cacheKey);

            Log::debug('Voice routing cache: Business hours schedule cache invalidated', [
                'organization_id' => $organizationId,
                'cache_key' => $cacheKey,
            ]);
        } catch (\Exception $e) {
            Log::warning('Voice routing cache: Failed to invalidate business hours cache', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear all routing cache for an organization
     *
     * Useful for bulk operations or testing.
     */
    public function clearOrganizationCache(int $organizationId): void
    {
        try {
            // Clear business hours cache
            $this->invalidateBusinessHoursSchedule($organizationId);

            Log::info('Voice routing cache: Organization cache cleared', [
                'organization_id' => $organizationId,
            ]);
        } catch (\Exception $e) {
            Log::warning('Voice routing cache: Failed to clear organization cache', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build cache key for extension lookup
     *
     * Format: routing:extension:{org_id}:{ext_number}
     */
    private function buildExtensionCacheKey(int $organizationId, string $extensionNumber): string
    {
        return sprintf('%s:%d:%s', self::EXTENSION_KEY_PREFIX, $organizationId, $extensionNumber);
    }

    /**
     * Build cache key for business hours schedule
     *
     * Format: routing:business_hours:{org_id}
     */
    private function buildBusinessHoursCacheKey(int $organizationId): string
    {
        return sprintf('%s:%d', self::BUSINESS_HOURS_KEY_PREFIX, $organizationId);
    }

    /**
     * Get AI Assistant Load Balancer with caching
     *
     * Implements cache-aside pattern for load balancer lookups.
     * Loads full configuration including members and fallback relationships.
     */
    public function getAiAssistantLoadBalancer(int $albsId): ?AiAssistantLoadBalancer
    {
        $cacheKey = $this->buildAlbsCacheKey($albsId);

        try {
            $loadBalancer = Cache::remember(
                $cacheKey,
                $this->getAlbsCacheTtl(),
                function () use ($albsId) {
                    Log::debug('Voice routing cache: ALBS cache miss', [
                        'albs_id' => $albsId,
                    ]);

                    return AiAssistantLoadBalancer::with([
                        'members' => function ($query) {
                            $query->where('status', 'active')
                                ->orderBy('position');
                        },
                        'members.aiAssistant' => function ($query) {
                            $query->where('status', 'active');
                        },
                        'fallbackExtension',
                        'fallbackRingGroup',
                        'fallbackIvrMenu',
                        'fallbackAiAssistant',
                    ])->find($albsId);
                }
            );

            if ($loadBalancer) {
                Log::debug('Voice routing cache: ALBS retrieved', [
                    'albs_id' => $albsId,
                    'from_cache' => Cache::has($cacheKey),
                ]);
            }

            return $loadBalancer;
        } catch (\Exception $e) {
            Log::warning('Voice routing cache: ALBS cache unavailable, falling back to database', [
                'albs_id' => $albsId,
                'error' => $e->getMessage(),
            ]);

            return AiAssistantLoadBalancer::with([
                'members' => function ($query) {
                    $query->where('status', 'active')
                        ->orderBy('position');
                },
                'members.aiAssistant',
                'fallbackExtension',
                'fallbackRingGroup',
                'fallbackIvrMenu',
                'fallbackAiAssistant',
            ])->find($albsId);
        }
    }

    /**
     * Invalidate AI Assistant Load Balancer cache
     *
     * Called when a load balancer is updated to ensure cache consistency.
     * Also clears the round robin counter.
     */
    public function invalidateAiAssistantLoadBalancer(int $albsId): void
    {
        $cacheKey = $this->buildAlbsCacheKey($albsId);

        try {
            Cache::forget($cacheKey);
            // Also clear round robin counter
            Cache::forget("albs:rr:{$albsId}");

            Log::debug('Voice routing cache: ALBS cache invalidated', [
                'albs_id' => $albsId,
                'cache_key' => $cacheKey,
            ]);
        } catch (\Exception $e) {
            Log::warning('Voice routing cache: Failed to invalidate ALBS cache', [
                'albs_id' => $albsId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build cache key for AI Assistant Load Balancer
     *
     * Format: albs:{albs_id}
     */
    private function buildAlbsCacheKey(int $albsId): string
    {
        return sprintf('%s:%d', self::ALBS_KEY_PREFIX, $albsId);
    }
}
