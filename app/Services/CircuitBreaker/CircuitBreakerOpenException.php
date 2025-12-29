<?php

declare(strict_types=1);

namespace App\Services\CircuitBreaker;

use Exception;

/**
 * Exception thrown when circuit breaker is open.
 *
 * Indicates that the protected service is currently unavailable
 * and requests are being failed fast to prevent cascading failures.
 */
class CircuitBreakerOpenException extends Exception
{
    public function __construct(string $message = 'Circuit breaker is open')
    {
        parent::__construct($message);
    }
}
