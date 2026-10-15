<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

/**
 * A Cloudonix REST call failed. `status` is the HTTP status, or 0 when the
 * API could not be reached at all (timeout, DNS, connection refused).
 */
class CloudonixApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isAuthFailure(): bool
    {
        return in_array($this->status, [401, 403], true);
    }

    public function isConnectionFailure(): bool
    {
        return $this->status === 0;
    }
}
