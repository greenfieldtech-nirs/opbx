<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

/**
 * Exception for webhook validation failures.
 *
 * Indicates the webhook payload is invalid or malformed.
 * Returns 400 Bad Request - client should NOT retry.
 *
 * Use cases:
 * - Missing required fields
 * - Invalid data types
 * - Malformed JSON
 * - Failed validation rules
 */
class WebhookValidationException extends WebhookException
{
    /**
     * Validation errors (optional).
     */
    protected array $errors = [];

    /**
     * Create a new validation exception.
     *
     * @param string $message Human-readable error message
     * @param array $errors Structured validation errors
     */
    public function __construct(string $message = 'Validation failed', array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function getHttpStatus(): int
    {
        return 400; // Bad Request
    }

    public function shouldRetry(): bool
    {
        return false; // Don't retry validation errors
    }

    /**
     * Get validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function toArray(): array
    {
        $data = parent::toArray();

        if (!empty($this->errors)) {
            $data['errors'] = $this->errors;
        }

        return $data;
    }
}
