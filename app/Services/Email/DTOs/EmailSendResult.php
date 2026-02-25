<?php

declare(strict_types=1);

namespace App\Services\Email\DTOs;

use Carbon\Carbon;

/**
 * Email Send Result DTO
 *
 * Represents the result of an email send operation.
 */
readonly class EmailSendResult
{
    /**
     * Create a new email send result.
     *
     * @param  bool  $success  Whether the send was successful
     * @param  string  $providerUsed  The provider that was used (e.g., 'mailgun')
     * @param  string|null  $messageId  The provider's message ID for tracking
     * @param  string|null  $errorMessage  Error message if send failed
     * @param  array  $metadata  Additional provider-specific metadata
     * @param  Carbon|null  $timestamp  When the result was created
     */
    public function __construct(
        public bool $success,
        public string $providerUsed,
        public ?string $messageId = null,
        public ?string $errorMessage = null,
        public array $metadata = [],
        public ?Carbon $timestamp = null
    ) {
        $this->timestamp ??= Carbon::now();
    }

    /**
     * Create a successful result.
     */
    public static function success(
        string $providerUsed,
        string $messageId,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            providerUsed: $providerUsed,
            messageId: $messageId,
            metadata: $metadata
        );
    }

    /**
     * Create a failed result.
     */
    public static function failure(
        string $providerUsed,
        string $errorMessage,
        array $metadata = []
    ): self {
        return new self(
            success: false,
            providerUsed: $providerUsed,
            errorMessage: $errorMessage,
            metadata: $metadata
        );
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'provider_used' => $this->providerUsed,
            'message_id' => $this->messageId,
            'error_message' => $this->errorMessage,
            'metadata' => $this->metadata,
            'timestamp' => $this->timestamp?->toIso8601String(),
        ];
    }
}
