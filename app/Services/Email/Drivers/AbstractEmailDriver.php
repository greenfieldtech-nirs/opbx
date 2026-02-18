<?php

declare(strict_types=1);

namespace App\Services\Email\Drivers;

use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailRecipient;
use App\Services\Email\Exceptions\DriverException;
use Illuminate\Support\Facades\Log;

/**
 * Abstract Email Driver
 *
 * Base class for all email service provider drivers.
 * Provides common functionality for HTTP clients, logging, and error handling.
 */
abstract class AbstractEmailDriver implements TransactionalEmailInterface
{
    /**
     * Configuration array.
     */
    protected array $config;

    /**
     * Create a new driver instance.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Send an email asynchronously via queue.
     */
    public function sendAsync(EmailMessage $message): string
    {
        $job = new \App\Services\Email\Jobs\SendTransactionalEmailJob($message, $this->getDriverName());
        $jobId = (string) \Illuminate\Support\Str::uuid();

        dispatch($job)->onQueue($this->config['queue'] ?? 'default');

        return $jobId;
    }

    /**
     * Format an address for email headers.
     */
    protected function formatAddress(EmailRecipient $recipient): string
    {
        if ($recipient->name) {
            return sprintf('"%s" <%s>', $recipient->name, $recipient->email);
        }

        return $recipient->email;
    }

    /**
     * Format multiple addresses.
     */
    protected function formatAddresses(array $recipients): array
    {
        return array_map(fn (EmailRecipient $r) => $this->formatAddress($r), $recipients);
    }

    /**
     * Normalize a driver exception.
     */
    protected function normalizeException(\Exception $e): DriverException
    {
        return new DriverException(
            message: $e->getMessage(),
            driver: $this->getDriverName(),
            code: $e->getCode(),
            previous: $e
        );
    }

    /**
     * Log an email send attempt.
     */
    protected function logSend(EmailMessage $message, string $status, array $metadata = []): void
    {
        Log::info('Email send attempt', [
            'correlation_id' => $message->correlationId,
            'driver' => $this->getDriverName(),
            'status' => $status,
            'from' => $message->from->email,
            'to' => array_map(fn ($r) => $r->email, $message->to),
            'subject' => $message->subject,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Check if required configuration keys are present.
     *
     * @throws \App\Services\Email\Exceptions\InvalidConfigurationException
     */
    protected function validateConfig(array $keys): void
    {
        foreach ($keys as $key) {
            if (empty($this->config[$key])) {
                throw new \App\Services\Email\Exceptions\InvalidConfigurationException(
                    "Missing required configuration key: {$key}",
                    "transactional_email.providers.{$this->getDriverName()}.{$key}"
                );
            }
        }
    }

    /**
     * Perform a health check on the driver.
     */
    abstract public function healthCheck(): bool;
}
