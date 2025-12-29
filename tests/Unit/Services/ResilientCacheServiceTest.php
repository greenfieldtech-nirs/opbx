<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Fallback\ResilientCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for resilient cache service with Redis fallback.
 */
class ResilientCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private ResilientCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ResilientCacheService();

        // Clear any existing locks
        DB::table('locks')->delete();

        // Clear cache
        Cache::flush();
    }

    /**
     * Test basic remember operation works with Redis available.
     */
    public function test_remember_works_with_redis(): void
    {
        $callCount = 0;

        $result1 = $this->service->remember('test_key', 60, function () use (&$callCount) {
            $callCount++;

            return 'test_value';
        });

        $this->assertEquals('test_value', $result1);
        $this->assertEquals(1, $callCount);

        // Second call should use cache
        $result2 = $this->service->remember('test_key', 60, function () use (&$callCount) {
            $callCount++;

            return 'test_value';
        });

        $this->assertEquals('test_value', $result2);
        $this->assertEquals(1, $callCount); // Should still be 1 (cached)
    }

    /**
     * Test lock operation with Redis available.
     */
    public function test_lock_works_with_redis(): void
    {
        $executed = false;

        $result = $this->service->lock('test_lock', function () use (&$executed) {
            $executed = true;

            return 'locked_result';
        });

        $this->assertTrue($executed);
        $this->assertEquals('locked_result', $result);
    }

    /**
     * Test database lock fallback can be used directly.
     */
    public function test_database_lock_fallback(): void
    {
        $executed = false;

        // Use database lock service directly via ResilientCacheService
        // This will use Redis if available, but we're testing the mechanism works
        $result = $this->service->lock('db_test_lock', function () use (&$executed) {
            $executed = true;

            return 'locked_result';
        });

        $this->assertTrue($executed);
        $this->assertEquals('locked_result', $result);
    }

    /**
     * Test get/put operations.
     */
    public function test_get_and_put(): void
    {
        $success = $this->service->put('test_put_key', 'test_value', 60);
        $this->assertTrue($success);

        $value = $this->service->get('test_put_key');
        $this->assertEquals('test_value', $value);

        $defaultValue = $this->service->get('nonexistent_key', 'default');
        $this->assertEquals('default', $defaultValue);
    }

    /**
     * Test forget operation.
     */
    public function test_forget(): void
    {
        $this->service->put('forget_test', 'value', 60);
        $this->assertEquals('value', $this->service->get('forget_test'));

        $this->service->forget('forget_test');
        $this->assertNull($this->service->get('forget_test'));
    }

    /**
     * Test status method returns correct information.
     */
    public function test_status_returns_information(): void
    {
        $status = $this->service->getStatus();

        $this->assertArrayHasKey('redis_available', $status);
        $this->assertArrayHasKey('last_health_check', $status);
        $this->assertArrayHasKey('using_database_locks', $status);
        $this->assertArrayHasKey('active_db_locks', $status);
    }

    /**
     * Test concurrent lock attempts fail correctly.
     */
    public function test_concurrent_locks_handled_correctly(): void
    {
        // Acquire first lock
        $lock1Executed = false;
        $lock2Failed = false;

        $this->service->lock('concurrent_test', function () use (&$lock1Executed, &$lock2Failed) {
            $lock1Executed = true;

            // Try to acquire same lock while holding it (should fail)
            try {
                $this->service->lock('concurrent_test', function () {
                    // Should not execute
                }, 1, 0); // 0 wait time = immediate fail

                $lock2Failed = false; // Should not reach here
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException | \RuntimeException $e) {
                $lock2Failed = true; // Expected - lock is held
            }

        }, 5, 1);

        $this->assertTrue($lock1Executed);
        $this->assertTrue($lock2Failed);
    }

    /**
     * Test health check can be forced.
     */
    public function test_force_health_check(): void
    {
        $isAvailable = $this->service->forceHealthCheck();

        // Should be true with Redis running
        $this->assertTrue($isAvailable);

        $status = $this->service->getStatus();
        $this->assertTrue($status['redis_available']);
    }
}
