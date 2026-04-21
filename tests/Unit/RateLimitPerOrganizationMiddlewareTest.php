<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\RateLimitPerOrganization;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RateLimitPerOrganizationMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    /**
     * Test that incrementAttempts returns 0 when Redis fails.
     */
    public function test_increment_attempts_returns_zero_when_redis_fails(): void
    {
        $middleware = new RateLimitPerOrganization();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('incrementAttempts');
        $method->setAccessible(true);

        // Mock Cache to throw exception
        Cache::shouldReceive('get')
            ->andThrow(new \Exception('Connection refused'));

        $attempts = $method->invoke($middleware, 'test_key', 1);

        $this->assertEquals(0, $attempts);
    }

    /**
     * Test that incrementAttempts works normally when Redis is available.
     */
    public function test_increment_attempts_works_normally_when_redis_available(): void
    {
        $middleware = new RateLimitPerOrganization();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('incrementAttempts');
        $method->setAccessible(true);

        // Mock Cache for normal operation
        Cache::shouldReceive('get')
            ->andReturn(null); // First call returns null

        Cache::shouldReceive('put')
            ->once();

        $attempts = $method->invoke($middleware, 'test_key', 1);

        $this->assertEquals(1, $attempts);
    }

    /**
     * Test that incrementAttempts handles Cache::put failures gracefully.
     */
    public function test_increment_attempts_handles_put_failures_gracefully(): void
    {
        $middleware = new RateLimitPerOrganization();
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('incrementAttempts');
        $method->setAccessible(true);

        // Mock Cache::get to return null (first request)
        Cache::shouldReceive('get')
            ->andReturn(null);

        // Mock Cache::put to throw exception
        Cache::shouldReceive('put')
            ->andThrow(new \Exception('Connection refused'));

        $attempts = $method->invoke($middleware, 'test_key', 1);

        $this->assertEquals(0, $attempts);
    }
}
