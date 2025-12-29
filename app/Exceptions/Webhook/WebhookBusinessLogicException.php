<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

/**
 * Exception for business logic failures in webhooks.
 *
 * Indicates the request was valid but cannot be processed due to business rules.
 * Returns 422 Unprocessable Entity - client should NOT retry without changes.
 *
 * Use cases:
 * - Resource not found (organization, DID, extension)
 * - Invalid state transition
 * - Business rule violation
 * - Insufficient permissions
 */
class WebhookBusinessLogicException extends WebhookException
{
    public function getHttpStatus(): int
    {
        return 422; // Unprocessable Entity
    }

    public function shouldRetry(): bool
    {
        return false; // Don't retry business logic errors
    }
}
