<?php

declare(strict_types=1);

namespace App\Services\Email\Validation;

/**
 * Validation Result DTO
 *
 * Represents the result of a configuration validation.
 */
readonly class ValidationResult
{
    /**
     * Create a new validation result.
     *
     * @param  bool  $isValid  Whether the validation passed
     * @param  string|null  $provider  The enabled provider (if valid and one exists)
     * @param  string|null  $error  The error message (if invalid)
     * @param  array  $enabledProviders  List of enabled providers (if multiple found)
     */
    public function __construct(
        public bool $isValid,
        public ?string $provider = null,
        public ?string $error = null,
        public array $enabledProviders = []
    ) {}

    /**
     * Create a successful validation result.
     */
    public static function success(?string $provider = null): self
    {
        return new self(
            isValid: true,
            provider: $provider
        );
    }

    /**
     * Create a failed validation result.
     */
    public static function failure(string $error, array $enabledProviders = []): self
    {
        return new self(
            isValid: false,
            error: $error,
            enabledProviders: $enabledProviders
        );
    }

    /**
     * Check if validation passed.
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * Get the error message.
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Get the enabled provider.
     */
    public function getProvider(): ?string
    {
        return $this->provider;
    }

    /**
     * Get the list of enabled providers.
     */
    public function getEnabledProviders(): array
    {
        return $this->enabledProviders;
    }
}
