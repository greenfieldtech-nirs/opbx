<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

/**
 * Exception for transient webhook processing failures.
 *
 * Indicates a temporary failure that may succeed if retried.
 * Returns 503 Service Unavailable - client SHOULD retry with backoff.
 *
 * Use cases:
 * - Database connection failures
 * - Redis unavailable
 * - External API timeouts
 * - Rate limit exceeded (internal)
 * - Temporary resource exhaustion
 */
class WebhookTransientException extends WebhookException
{
    /**
     * Retry delay in seconds.
     */
    protected int $retryAfter;

    /**
     * Create a new transient exception.
     *
     * @param string $message Human-readable error message
     * @param int $retryAfter Seconds to wait before retry (default: 30)
     */
    public function __construct(string $message = 'Service temporarily unavailable', int $retryAfter = 30)
    {
        parent::__construct($message);
        $this->retryAfter = $retryAfter;
    }

    public function getHttpStatus(): int
    {
        return 503; // Service Unavailable
    }

    public function shouldRetry(): bool
    {
        return true; // Retry transient errors
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
