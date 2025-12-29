<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

use Exception;

/**
 * Base exception for all webhook-related errors.
 *
 * Provides consistent error handling for webhook endpoints with:
 * - Appropriate HTTP status codes
 * - Retry behavior hints for external systems
 * - Structured error responses
 */
abstract class WebhookException extends Exception
{
    /**
     * Get the HTTP status code for this exception.
     */
    abstract public function getHttpStatus(): int;

    /**
     * Determine if the external system should retry this request.
     *
     * @return bool True if retryable (transient error), false otherwise
     */
    abstract public function shouldRetry(): bool;

    /**
     * Get the retry-after delay in seconds (if retryable).
     *
     * @return int|null Seconds to wait before retry, or null for default behavior
     */
    public function getRetryAfter(): ?int
    {
        return $this->shouldRetry() ? 30 : null;
    }

    /**
     * Get a structured error response array.
     */
    public function toArray(): array
    {
        return [
            'error' => $this->getMessage(),
            'type' => class_basename($this),
            'retryable' => $this->shouldRetry(),
        ];
    }
}
