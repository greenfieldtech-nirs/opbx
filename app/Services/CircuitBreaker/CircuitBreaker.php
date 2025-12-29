<?php

declare(strict_types=1);

namespace App\Services\CircuitBreaker;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit breaker implementation for external service calls.
 *
 * Prevents cascading failures by:
 * - Opening the circuit after threshold failures
 * - Failing fast when circuit is open
 * - Allowing test requests in half-open state
 * - Automatically recovering when service is healthy
 */
class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    /**
     * Create a new circuit breaker.
     *
     * @param string $serviceName Unique identifier for this service
     * @param int $failureThreshold Number of failures before opening circuit
     * @param int $timeoutSeconds Timeout for individual requests
     * @param int $retryAfterSeconds Seconds to wait before attempting reset
     */
    public function __construct(
        private readonly string $serviceName,
        private readonly int $failureThreshold = 5,
        private readonly int $timeoutSeconds = 30,
        private readonly int $retryAfterSeconds = 60
    ) {
    }

    /**
     * Execute a callback with circuit breaker protection.
     *
     * @param callable $callback The operation to execute
     * @param callable|null $fallback Optional fallback when circuit is open
     * @return mixed Result from callback or fallback
     * @throws CircuitBreakerOpenException If circuit is open and no fallback
     * @throws \Exception If callback fails and circuit should remain closed
     */
    public function call(callable $callback, ?callable $fallback = null): mixed
    {
        $state = $this->getState();

        // Circuit is open - fail fast
        if ($state === self::STATE_OPEN) {
            if ($this->shouldAttemptReset()) {
                // Transition to half-open for testing
                $this->setState(self::STATE_HALF_OPEN);
                Log::info('Circuit breaker transitioning to half-open', [
                    'service' => $this->serviceName,
                ]);
            } else {
                Log::warning('Circuit breaker is open - failing fast', [
                    'service' => $this->serviceName,
                    'retry_after' => $this->retryAfterSeconds,
                ]);

                if ($fallback) {
                    return $fallback();
                }

                throw new CircuitBreakerOpenException(
                    "Circuit breaker is open for service: {$this->serviceName}. " .
                    "Retry after {$this->retryAfterSeconds} seconds."
                );
            }
        }

        // Execute the protected operation
        try {
            $result = $callback();

            // Success - record and potentially close circuit
            $this->recordSuccess();

            // Check current state (not initial state) to see if we should close
            $currentState = $this->getState();
            if ($currentState === self::STATE_HALF_OPEN || $currentState === self::STATE_OPEN) {
                $this->close();
                Log::info('Circuit breaker closed after successful test', [
                    'service' => $this->serviceName,
                    'previous_state' => $currentState,
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            // Failure - record and potentially open circuit
            $this->recordFailure();

            if ($this->shouldOpen()) {
                $this->open();
            }

            throw $e;
        }
    }

    /**
     * Get the current state of the circuit breaker.
     */
    public function getState(): string
    {
        return Cache::get($this->getStateKey(), self::STATE_CLOSED);
    }

    /**
     * Set the circuit breaker state.
     */
    private function setState(string $state): void
    {
        Cache::put(
            $this->getStateKey(),
            $state,
            now()->addMinutes(60) // State TTL
        );
    }

    /**
     * Record a successful operation.
     */
    private function recordSuccess(): void
    {
        // Reset failure count on success
        Cache::forget($this->getFailuresKey());
    }

    /**
     * Record a failed operation.
     */
    private function recordFailure(): void
    {
        $key = $this->getFailuresKey();
        $failures = Cache::get($key, 0) + 1;

        // Store failures with 5-minute rolling window
        Cache::put($key, $failures, now()->addMinutes(5));

        // Record last failure timestamp
        Cache::put(
            $this->getLastFailureKey(),
            now()->toIso8601String(),
            now()->addHour()
        );

        Log::warning('Circuit breaker recorded failure', [
            'service' => $this->serviceName,
            'failures' => $failures,
            'threshold' => $this->failureThreshold,
        ]);
    }

    /**
     * Check if circuit breaker should open based on failure count.
     */
    private function shouldOpen(): bool
    {
        $failures = Cache::get($this->getFailuresKey(), 0);

        return $failures >= $this->failureThreshold;
    }

    /**
     * Open the circuit breaker.
     */
    private function open(): void
    {
        Log::warning('Opening circuit breaker', [
            'service' => $this->serviceName,
            'failures' => Cache::get($this->getFailuresKey(), 0),
            'retry_after' => $this->retryAfterSeconds,
        ]);

        $this->setState(self::STATE_OPEN);

        // Store when circuit was opened
        Cache::put(
            $this->getOpenedAtKey(),
            now()->timestamp,
            now()->addSeconds($this->retryAfterSeconds * 2)
        );
    }

    /**
     * Close the circuit breaker.
     */
    private function close(): void
    {
        $this->setState(self::STATE_CLOSED);
        Cache::forget($this->getFailuresKey());
        Cache::forget($this->getOpenedAtKey());

        Log::info('Circuit breaker closed', [
            'service' => $this->serviceName,
        ]);
    }

    /**
     * Check if enough time has passed to attempt circuit reset.
     */
    private function shouldAttemptReset(): bool
    {
        $openedAt = Cache::get($this->getOpenedAtKey());

        if ($openedAt === null) {
            return true; // No opened timestamp, allow reset
        }

        $elapsedSeconds = now()->timestamp - $openedAt;

        return $elapsedSeconds >= $this->retryAfterSeconds;
    }

    /**
     * Get circuit breaker status for monitoring.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        $state = $this->getState();
        $failures = Cache::get($this->getFailuresKey(), 0);
        $lastFailure = Cache::get($this->getLastFailureKey());
        $openedAt = Cache::get($this->getOpenedAtKey());

        return [
            'service' => $this->serviceName,
            'state' => $state,
            'failures' => $failures,
            'failure_threshold' => $this->failureThreshold,
            'last_failure' => $lastFailure,
            'opened_at' => $openedAt ? date('Y-m-d H:i:s', (int) $openedAt) : null,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'is_healthy' => $state === self::STATE_CLOSED,
        ];
    }

    /**
     * Manually reset the circuit breaker.
     */
    public function reset(): void
    {
        $this->close();
        Log::info('Circuit breaker manually reset', [
            'service' => $this->serviceName,
        ]);
    }

    // =========================================================================
    // Cache Key Helpers
    // =========================================================================

    private function getStateKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:state";
    }

    private function getFailuresKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:failures";
    }

    private function getLastFailureKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:last_failure";
    }

    private function getOpenedAtKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:opened_at";
    }
}
