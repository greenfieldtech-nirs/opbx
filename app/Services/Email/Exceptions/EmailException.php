<?php

declare(strict_types=1);

namespace App\Services\Email\Exceptions;

use Exception;

/**
 * Base Email Exception
 *
 * All email-related exceptions extend this class.
 */
class EmailException extends Exception
{
    /**
     * Additional context data.
     */
    protected array $context = [];

    /**
     * Create a new email exception.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Exception $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get the context data.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set context data.
     *
     * @return $this
     */
    public function setContext(array $context): self
    {
        $this->context = $context;

        return $this;
    }
}
