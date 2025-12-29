<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\CircuitBreaker\CircuitBreakerOpenException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests for Circuit Breaker implementation.
 *
 * Verifies circuit breaker opens after threshold failures,
 * fails fast when open, and recovers correctly.
 */
class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    /**
     * Test circuit breaker starts in closed state.
     */
    public function test_circuit_breaker_starts_closed(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 3);

        $this->assertEquals('closed', $cb->getState());
        $this->assertTrue($cb->getStatus()['is_healthy']);
    }

    /**
     * Test successful calls keep circuit closed.
     */
    public function test_successful_calls_keep_circuit_closed(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 3);

        // Execute 5 successful calls
        for ($i = 0; $i < 5; $i++) {
            $result = $cb->call(fn () => 'success');
            $this->assertEquals('success', $result);
        }

        $this->assertEquals('closed', $cb->getState());
        $this->assertEquals(0, $cb->getStatus()['failures']);
    }

    /**
     * Test circuit opens after threshold failures.
     */
    public function test_circuit_opens_after_threshold_failures(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 3, retryAfterSeconds: 60);

        // Trigger 3 failures
        for ($i = 0; $i < 3; $i++) {
            try {
                $cb->call(function () {
                    throw new \RuntimeException('API failure');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        // Circuit should now be open
        $this->assertEquals('open', $cb->getState());
        $this->assertFalse($cb->getStatus()['is_healthy']);
    }

    /**
     * Test circuit fails fast when open.
     */
    public function test_circuit_fails_fast_when_open(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 2, retryAfterSeconds: 60);

        // Open the circuit
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->call(fn () => throw new \RuntimeException('Failure'));
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->assertEquals('open', $cb->getState());

        // Next call should fail fast without executing callback
        $this->expectException(CircuitBreakerOpenException::class);
        $cb->call(fn () => 'should not execute');
    }

    /**
     * Test fallback is called when circuit is open.
     */
    public function test_fallback_called_when_circuit_open(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 2, retryAfterSeconds: 60);

        // Open the circuit
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->call(fn () => throw new \RuntimeException('Failure'));
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        // Call with fallback
        $result = $cb->call(
            callback: fn () => 'should not execute',
            fallback: fn () => 'fallback data'
        );

        $this->assertEquals('fallback data', $result);
    }

    /**
     * Test circuit transitions to half-open after retry period.
     */
    public function test_circuit_transitions_to_half_open(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 2, retryAfterSeconds: 1);

        // Open the circuit
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->call(fn () => throw new \RuntimeException('Failure'));
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->assertEquals('open', $cb->getState());

        // Wait for retry period
        sleep(2);

        // Next call should transition to half-open and execute
        $result = $cb->call(fn () => 'test success');

        $this->assertEquals('test success', $result);
        $this->assertEquals('closed', $cb->getState());
    }

    /**
     * Test circuit closes after successful test in half-open state.
     */
    public function test_circuit_closes_after_successful_half_open_test(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 2, retryAfterSeconds: 1);

        // Open the circuit
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->call(fn () => throw new \RuntimeException('Failure'));
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->assertEquals('open', $cb->getState());

        // Wait for retry period
        sleep(2);

        // Successful call should close circuit
        $cb->call(fn () => 'success');

        $this->assertEquals('closed', $cb->getState());
        $this->assertEquals(0, $cb->getStatus()['failures']);
    }

    /**
     * Test circuit reopens if half-open test fails.
     */
    public function test_circuit_reopens_if_half_open_test_fails(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 2, retryAfterSeconds: 1);

        // Open the circuit
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->call(fn () => throw new \RuntimeException('Failure'));
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        // Wait for retry period
        sleep(2);

        // Failed call should reopen circuit
        try {
            $cb->call(fn () => throw new \RuntimeException('Still failing'));
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertEquals('open', $cb->getState());
    }

    /**
     * Test manual reset works.
     */
    public function test_manual_reset_closes_circuit(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 2);

        // Open the circuit
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->call(fn () => throw new \RuntimeException('Failure'));
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->assertEquals('open', $cb->getState());

        // Manual reset
        $cb->reset();

        $this->assertEquals('closed', $cb->getState());
        $this->assertEquals(0, $cb->getStatus()['failures']);
    }

    /**
     * Test status method returns complete information.
     */
    public function test_status_returns_complete_information(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 5, retryAfterSeconds: 30);

        $status = $cb->getStatus();

        $this->assertArrayHasKey('service', $status);
        $this->assertArrayHasKey('state', $status);
        $this->assertArrayHasKey('failures', $status);
        $this->assertArrayHasKey('failure_threshold', $status);
        $this->assertArrayHasKey('is_healthy', $status);
        $this->assertArrayHasKey('retry_after_seconds', $status);

        $this->assertEquals('test-service', $status['service']);
        $this->assertEquals(5, $status['failure_threshold']);
        $this->assertEquals(30, $status['retry_after_seconds']);
    }

    /**
     * Test failure count increments correctly.
     */
    public function test_failure_count_increments(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 5);

        // Record 3 failures
        for ($i = 0; $i < 3; $i++) {
            try {
                $cb->call(fn () => throw new \RuntimeException('Failure'));
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $status = $cb->getStatus();
        $this->assertEquals(3, $status['failures']);
        $this->assertEquals('closed', $status['state']); // Still closed (threshold is 5)
    }

    /**
     * Test successful call resets failure count.
     */
    public function test_successful_call_resets_failure_count(): void
    {
        $cb = new CircuitBreaker('test-service', failureThreshold: 5);

        // Record 3 failures
        for ($i = 0; $i < 3; $i++) {
            try {
                $cb->call(fn () => throw new \RuntimeException('Failure'));
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->assertEquals(3, $cb->getStatus()['failures']);

        // One successful call
        $cb->call(fn () => 'success');

        // Failure count should reset
        $this->assertEquals(0, $cb->getStatus()['failures']);
    }
}
