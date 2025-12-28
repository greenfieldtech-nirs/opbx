<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Models\BusinessHoursSchedule;
use App\Models\Extension;
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
     * TTL for extension cache entries (30 minutes)
     */
    private const EXTENSION_CACHE_TTL = 1800;

    /**
     * TTL for business hours schedule cache entries (15 minutes)
     */
    private const BUSINESS_HOURS_CACHE_TTL = 900;

    /**
     * Cache key prefix for extensions
     */
    private const EXTENSION_KEY_PREFIX = 'routing:extension';

    /**
     * Cache key prefix for business hours schedules
     */
    private const BUSINESS_HOURS_KEY_PREFIX = 'routing:business_hours';

    /**
     * Get extension by organization and extension number with caching
     *
     * Implements cache-aside pattern:
     * 1. Check cache first
     * 2. If miss, load from database
     * 3. Store in cache for future requests
     * 4. If cache unavailable, fallback to database
     *
     * @param int $organizationId
     * @param string $extensionNumber
     * @return Extension|null
     */
    public function getExtension(int $organizationId, string $extensionNumber): ?Extension
    {
        $cacheKey = $this->buildExtensionCacheKey($organizationId, $extensionNumber);

        try {
            // Try to get from cache
            $extension = Cache::remember(
                $cacheKey,
                self::EXTENSION_CACHE_TTL,
                function () use ($organizationId, $extensionNumber) {
                    Log::debug('Voice routing cache: Extension cache miss, loading from database', [
                        'organization_id' => $organizationId,
                        'extension_number' => $extensionNumber,
                    ]);

                    return Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                        ->with('user')
                        ->where('organization_id', $organizationId)
                        ->where('extension_number', $extensionNumber)
                        ->first();
                }
            );

            if ($extension) {
                Log::debug('Voice routing cache: Extension retrieved', [
                    'organization_id' => $organizationId,
                    'extension_number' => $extensionNumber,
                    'extension_id' => $extension->id,
                    'from_cache' => Cache::has($cacheKey),
                ]);
            }

            return $extension;
        } catch (\Exception $e) {
            // If cache fails, fallback to direct database query
            Log::warning('Voice routing cache: Cache unavailable, falling back to database', [
                'organization_id' => $organizationId,
                'extension_number' => $extensionNumber,
                'error' => $e->getMessage(),
            ]);

            return Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                ->with('user')
                ->where('organization_id', $organizationId)
                ->where('extension_number', $extensionNumber)
                ->first();
        }
    }

    /**
     * Get active business hours schedule for organization with caching
     *
     * Implements cache-aside pattern for business hours schedules.
     *
     * @param int $organizationId
     * @return BusinessHoursSchedule|null
     */
    public function getActiveBusinessHoursSchedule(int $organizationId): ?BusinessHoursSchedule
    {
        $cacheKey = $this->buildBusinessHoursCacheKey($organizationId);

        try {
            // Try to get from cache
            $schedule = Cache::remember(
                $cacheKey,
                self::BUSINESS_HOURS_CACHE_TTL,
                function () use ($organizationId) {
                    Log::debug('Voice routing cache: Business hours cache miss, loading from database', [
                        'organization_id' => $organizationId,
                    ]);

                    return BusinessHoursSchedule::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                        ->where('organization_id', $organizationId)
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

            return BusinessHoursSchedule::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                ->where('organization_id', $organizationId)
                ->active()
                ->with(['scheduleDays.timeRanges', 'exceptions.timeRanges'])
                ->first();
        }
    }

    /**
     * Invalidate extension cache
     *
     * Called when an extension is updated to ensure cache consistency.
     *
     * @param int $organizationId
     * @param string $extensionNumber
     * @return void
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
     *
     * @param int $organizationId
     * @return void
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
     *
     * @param int $organizationId
     * @return void
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
     *
     * @param int $organizationId
     * @param string $extensionNumber
     * @return string
     */
    private function buildExtensionCacheKey(int $organizationId, string $extensionNumber): string
    {
        return sprintf('%s:%d:%s', self::EXTENSION_KEY_PREFIX, $organizationId, $extensionNumber);
    }

    /**
     * Build cache key for business hours schedule
     *
     * Format: routing:business_hours:{org_id}
     *
     * @param int $organizationId
     * @return string
     */
    private function buildBusinessHoursCacheKey(int $organizationId): string
    {
        return sprintf('%s:%d', self::BUSINESS_HOURS_KEY_PREFIX, $organizationId);
    }
}
