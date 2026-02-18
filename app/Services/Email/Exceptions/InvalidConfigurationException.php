<?php

declare(strict_types=1);

namespace App\Services\Email\Exceptions;

/**
 * Invalid Configuration Exception
 *
 * Thrown when email configuration is invalid.
 */
class InvalidConfigurationException extends EmailException
{
    /**
     * The configuration key that caused the error.
     */
    protected ?string $configKey;

    /**
     * Create a new invalid configuration exception.
     */
    public function __construct(
        string $message,
        ?string $configKey = null,
        int $code = 0,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->configKey = $configKey;
    }

    /**
     * Get the configuration key.
     */
    public function getConfigKey(): ?string
    {
        return $this->configKey;
    }
}
