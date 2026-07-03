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
     * Test that incrementAttempts returns 1 when the key is added successfully.
     */
    public function test_increment_attempts_returns_one_when_key_is_added(): void
    {
        $middleware = new RateLimitPerOrganization;
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('incrementAttempts');
        $method->setAccessible(true);

        Cache::shouldReceive('add')
            ->once()
            ->with('test_key', 1, 60)
            ->andReturn(true);

        $attempts = $method->invoke($middleware, 'test_key', 1);

        $this->assertEquals(1, $attempts);
    }

    /**
     * Test that incrementAttempts increments when the key already exists.
     */
    public function test_increment_attempts_increments_existing_key(): void
    {
        $middleware = new RateLimitPerOrganization;
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('incrementAttempts');
        $method->setAccessible(true);

        Cache::shouldReceive('add')
            ->once()
            ->with('test_key', 1, 60)
            ->andReturn(false);

        Cache::shouldReceive('increment')
            ->once()
            ->with('test_key')
            ->andReturn(5);

        $attempts = $method->invoke($middleware, 'test_key', 1);

        $this->assertEquals(5, $attempts);
    }

    /**
     * Test that incrementAttempts propagates exceptions from the cache driver.
     */
    public function test_increment_attempts_propagates_cache_exceptions(): void
    {
        $middleware = new RateLimitPerOrganization;
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('incrementAttempts');
        $method->setAccessible(true);

        Cache::shouldReceive('add')
            ->once()
            ->andThrow(new \Exception('Connection refused'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Connection refused');

        $method->invoke($middleware, 'test_key', 1);
    }
}
