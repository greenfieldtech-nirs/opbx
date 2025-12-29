<?php

declare(strict_types=1);

namespace App\Services\Fallback;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Predis\Connection\ConnectionException as RedisConnectionException;

/**
 * Resilient caching service with graceful Redis degradation.
 *
 * Provides caching operations that gracefully handle Redis failures:
 * - Locking: Falls back to database-based locks
 * - Caching: Falls back to direct execution (no caching)
 */
class ResilientCacheService
{
    private DatabaseLockService $dbLockService;
    private bool $redisAvailable = true;
    private int $lastHealthCheck = 0;
    private const HEALTH_CHECK_INTERVAL = 60; // Check every 60 seconds

    public function __construct()
    {
        $this->dbLockService = new DatabaseLockService();
    }

    /**
     * Execute callback with distributed lock protection.
     *
     * Tries Redis lock first, falls back to database lock.
     *
     * @param string $key Lock key
     * @param callable $callback Callback to execute
     * @param int $seconds Lock duration
     * @param int $waitSeconds Max wait time for lock
     * @return mixed Result from callback
     */
    public function lock(string $key, callable $callback, int $seconds = 30, int $waitSeconds = 5): mixed
    {
        // Try Redis lock first
        if ($this->isRedisAvailable()) {
            try {
                return Cache::lock($key, $seconds)->block($waitSeconds, $callback);

            } catch (RedisConnectionException | \RedisException $e) {
                Log::warning('Redis lock failed, falling back to database', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                $this->markRedisUnavailable();
            } catch (\Exception $e) {
                // Other exceptions from callback should bubble up
                if (!$this->isRedisException($e)) {
                    throw $e;
                }

                Log::warning('Redis exception during lock, falling back to database', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                $this->markRedisUnavailable();
            }
        }

        // Fallback to database lock
        Log::info('Using database lock fallback', ['key' => $key]);

        return $this->dbLockService->block($key, $callback, $seconds, $waitSeconds);
    }

    /**
     * Get cached value or execute callback and cache result.
     *
     * Tries Redis cache first, falls back to direct execution (no caching).
     *
     * @param string $key Cache key
     * @param int $ttl TTL in seconds
     * @param callable $callback Callback to get value
     * @return mixed Cached or fresh value
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        // Try Redis cache first
        if ($this->isRedisAvailable()) {
            try {
                return Cache::remember($key, now()->addSeconds($ttl), $callback);

            } catch (RedisConnectionException | \RedisException $e) {
                Log::warning('Redis cache failed, executing without cache', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                $this->markRedisUnavailable();
            } catch (\Exception $e) {
                // Other exceptions from callback should bubble up
                if (!$this->isRedisException($e)) {
                    throw $e;
                }

                Log::warning('Redis exception during cache, executing without cache', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                $this->markRedisUnavailable();
            }
        }

        // Fallback: execute callback without caching
        Log::debug('Executing without cache (Redis unavailable)', ['key' => $key]);

        return $callback();
    }

    /**
     * Get value from cache.
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed Cached value or default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->isRedisAvailable()) {
            try {
                return Cache::get($key, $default);

            } catch (RedisConnectionException | \RedisException $e) {
                Log::warning('Redis get failed, returning default', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                $this->markRedisUnavailable();
            }
        }

        return $default;
    }

    /**
     * Put value in cache.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl TTL in seconds
     * @return bool True on success
     */
    public function put(string $key, mixed $value, int $ttl): bool
    {
        if ($this->isRedisAvailable()) {
            try {
                return Cache::put($key, $value, now()->addSeconds($ttl));

            } catch (RedisConnectionException | \RedisException $e) {
                Log::warning('Redis put failed, value not cached', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                $this->markRedisUnavailable();
            }
        }

        // Cannot cache, but don't fail
        return false;
    }

    /**
     * Forget/delete cached value.
     *
     * @param string $key Cache key
     * @return bool True on success
     */
    public function forget(string $key): bool
    {
        if ($this->isRedisAvailable()) {
            try {
                return Cache::forget($key);

            } catch (RedisConnectionException | \RedisException $e) {
                Log::warning('Redis forget failed', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                $this->markRedisUnavailable();
            }
        }

        return false;
    }

    /**
     * Check if Redis is available.
     *
     * Uses cached health check with periodic re-checks.
     */
    private function isRedisAvailable(): bool
    {
        $now = time();

        // If we recently marked Redis as unavailable, check if it's back
        if (!$this->redisAvailable && ($now - $this->lastHealthCheck) >= self::HEALTH_CHECK_INTERVAL) {
            $this->checkRedisHealth();
        }

        return $this->redisAvailable;
    }

    /**
     * Mark Redis as unavailable.
     */
    private function markRedisUnavailable(): void
    {
        if ($this->redisAvailable) {
            $this->redisAvailable = false;
            $this->lastHealthCheck = time();

            Log::error('Redis marked as unavailable - using fallbacks');
        }
    }

    /**
     * Check Redis health and update availability status.
     */
    private function checkRedisHealth(): void
    {
        $this->lastHealthCheck = time();

        try {
            $testKey = 'health_check_' . uniqid();
            Cache::put($testKey, true, 10);
            $result = Cache::get($testKey);
            Cache::forget($testKey);

            $wasAvailable = $this->redisAvailable;
            $this->redisAvailable = ($result === true);

            if ($this->redisAvailable && !$wasAvailable) {
                Log::info('Redis is available again');
            }

        } catch (\Exception $e) {
            $this->redisAvailable = false;
            Log::warning('Redis health check failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if exception is Redis-related.
     */
    private function isRedisException(\Exception $e): bool
    {
        return $e instanceof RedisConnectionException ||
               $e instanceof \RedisException ||
               str_contains($e->getMessage(), 'Redis') ||
               str_contains(get_class($e), 'Redis');
    }

    /**
     * Force Redis health check now.
     *
     * @return bool True if Redis is available
     */
    public function forceHealthCheck(): bool
    {
        $this->checkRedisHealth();

        return $this->redisAvailable;
    }

    /**
     * Get current Redis availability status.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return [
            'redis_available' => $this->redisAvailable,
            'last_health_check' => $this->lastHealthCheck,
            'last_health_check_ago' => time() - $this->lastHealthCheck,
            'using_database_locks' => !$this->redisAvailable,
            'active_db_locks' => !$this->redisAvailable ? count($this->dbLockService->getActiveLocks()) : 0,
        ];
    }
}
