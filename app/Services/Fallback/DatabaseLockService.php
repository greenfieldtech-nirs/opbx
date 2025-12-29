<?php

declare(strict_types=1);

namespace App\Services\Fallback;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Database-based locking service as fallback when Redis is unavailable.
 *
 * Provides distributed locking using database table instead of Redis.
 * Performance is lower than Redis but ensures system continues functioning.
 */
class DatabaseLockService
{
    /**
     * Attempt to acquire a lock.
     *
     * @param string $key Lock identifier
     * @param int $seconds Lock duration in seconds
     * @return string|null Lock owner ID if acquired, null if failed
     */
    public function acquire(string $key, int $seconds = 30): ?string
    {
        $owner = Str::uuid()->toString();

        try {
            // First, cleanup expired locks for this key
            $this->cleanupExpired($key);

            // Try to insert new lock
            DB::table('locks')->insert([
                'key' => $key,
                'owner' => $owner,
                'expires_at' => now()->addSeconds($seconds),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::debug('Database lock acquired', [
                'key' => $key,
                'owner' => $owner,
                'expires_in' => $seconds,
            ]);

            return $owner;

        } catch (QueryException $e) {
            // Lock already exists or other database error
            Log::debug('Failed to acquire database lock', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Release a lock by owner.
     *
     * @param string $key Lock identifier
     * @param string $owner Lock owner ID
     * @return bool True if released, false otherwise
     */
    public function release(string $key, string $owner): bool
    {
        $deleted = DB::table('locks')
            ->where('key', $key)
            ->where('owner', $owner)
            ->delete();

        if ($deleted > 0) {
            Log::debug('Database lock released', [
                'key' => $key,
                'owner' => $owner,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Release a lock by key (regardless of owner).
     *
     * @param string $key Lock identifier
     * @return bool True if released, false otherwise
     */
    public function forceRelease(string $key): bool
    {
        $deleted = DB::table('locks')
            ->where('key', $key)
            ->delete();

        if ($deleted > 0) {
            Log::debug('Database lock force released', [
                'key' => $key,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Execute callback with lock protection.
     *
     * @param string $key Lock identifier
     * @param callable $callback Callback to execute while holding lock
     * @param int $seconds Lock duration
     * @param int $waitSeconds Seconds to wait for lock acquisition
     * @return mixed Result from callback
     * @throws \RuntimeException If lock cannot be acquired
     */
    public function block(string $key, callable $callback, int $seconds = 30, int $waitSeconds = 5): mixed
    {
        $startTime = time();
        $owner = null;

        // Try to acquire lock, retry for waitSeconds
        while (true) {
            $owner = $this->acquire($key, $seconds);

            if ($owner !== null) {
                break; // Lock acquired
            }

            if ((time() - $startTime) >= $waitSeconds) {
                throw new \RuntimeException("Could not acquire database lock for key: {$key}");
            }

            // Wait 100ms before retry
            usleep(100000);
        }

        try {
            // Execute callback while holding lock
            return $callback();
        } finally {
            // Always release lock
            $this->release($key, $owner);
        }
    }

    /**
     * Cleanup expired locks for a specific key.
     *
     * @param string $key Lock identifier
     * @return int Number of locks cleaned up
     */
    private function cleanupExpired(string $key): int
    {
        return DB::table('locks')
            ->where('key', $key)
            ->where('expires_at', '<', now())
            ->delete();
    }

    /**
     * Cleanup all expired locks (for scheduled task).
     *
     * @return int Number of locks cleaned up
     */
    public function cleanupAll(): int
    {
        $deleted = DB::table('locks')
            ->where('expires_at', '<', now())
            ->delete();

        if ($deleted > 0) {
            Log::info('Cleaned up expired database locks', [
                'count' => $deleted,
            ]);
        }

        return $deleted;
    }

    /**
     * Check if a lock exists.
     *
     * @param string $key Lock identifier
     * @return bool True if lock exists and is not expired
     */
    public function exists(string $key): bool
    {
        // Cleanup expired first
        $this->cleanupExpired($key);

        return DB::table('locks')
            ->where('key', $key)
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Get all active locks (for monitoring).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveLocks(): array
    {
        return DB::table('locks')
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }
}
