<?php

declare(strict_types=1);

namespace App\Services\Email\Exceptions;

/**
 * Driver Exception
 *
 * Thrown when a specific email driver encounters an error.
 */
class DriverException extends EmailException
{
    /**
     * The driver that threw the exception.
     */
    protected string $driver;

    /**
     * Create a new driver exception.
     */
    public function __construct(
        string $message,
        string $driver,
        int $code = 0,
        ?\Exception $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->driver = $driver;
    }

    /**
     * Get the driver name.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }
}
