<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailLog;
use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailSendResult;
use App\Services\Email\Exceptions\EmailException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Transactional Email Service
 *
 * The main orchestrator for sending transactional emails.
 * Uses a single configured driver (no failover).
 */
class TransactionalEmailService implements TransactionalEmailInterface
{
    /**
     * Create a new service instance.
     */
    public function __construct(
        private TransactionalEmailInterface $driver,
        private array $config
    ) {}

    /**
     * Send an email synchronously.
     *
     * @throws EmailException
     */
    public function send(EmailMessage $message): EmailSendResult
    {
        $driverName = $this->driver->getDriverName();

        Log::info('Sending transactional email', [
            'correlation_id' => $message->correlationId,
            'driver' => $driverName,
            'to' => $message->to[0]->email ?? 'unknown',
            'subject' => $message->subject,
        ]);

        // Check rate limit
        $this->checkRateLimit();

        try {
            $result = $this->driver->send($message);
            $this->logSuccess($result, $message);

            return $result;
        } catch (\Exception $e) {
            Log::error('Transactional email driver send failed', [
                'correlation_id' => $message->correlationId,
                'driver' => $driverName,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            $this->logFailure($e, $message);
            throw $e;
        }
    }

    /**
     * Send an email asynchronously via queue.
     */
    public function sendAsync(EmailMessage $message): string
    {
        $job = new Jobs\SendTransactionalEmailJob($message, $this->config);
        $jobId = $message->correlationId ?? (string) Str::uuid();

        dispatch($job)->onQueue($this->config['queue'] ?? 'default');

        // Log as queued
        EmailLog::create([
            'correlation_id' => $jobId,
            'provider' => $this->driver->getDriverName(),
            'driver' => get_class($this->driver),
            'from_email' => $message->from->email,
            'to_email' => $message->to[0]->email ?? '',
            'subject' => $message->subject,
            'status' => EmailLog::STATUS_QUEUED,
        ]);

        return $jobId;
    }

    /**
     * Check if driver supports attachments.
     */
    public function supportsAttachments(): bool
    {
        return $this->driver->supportsAttachments();
    }

    /**
     * Check if driver supports templates.
     */
    public function supportsTemplates(): bool
    {
        return $this->driver->supportsTemplates();
    }

    /**
     * Get the driver name.
     */
    public function getDriverName(): string
    {
        return $this->driver->getDriverName();
    }

    /**
     * Perform a health check.
     */
    public function healthCheck(): bool
    {
        return $this->driver->healthCheck();
    }

    /**
     * Check rate limit for the current driver.
     *
     * @throws EmailException
     */
    private function checkRateLimit(): void
    {
        $driverName = $this->driver->getDriverName();
        $rateLimit = $this->config['providers'][$driverName]['rate_limit'] ?? 100;

        $allowed = RateLimiter::attempt(
            'email:'.$driverName,
            $rateLimit,
            fn () => true,
            60 // per minute
        );

        if (! $allowed) {
            throw new EmailException("Rate limit exceeded for {$driverName}");
        }
    }

    /**
     * Log a successful send.
     */
    private function logSuccess(EmailSendResult $result, EmailMessage $message): void
    {
        EmailLog::create([
            'correlation_id' => $message->correlationId,
            'provider' => $result->providerUsed,
            'driver' => get_class($this->driver),
            'from_email' => $message->from->email,
            'to_email' => $message->to[0]->email ?? '',
            'subject' => $message->subject,
            'status' => EmailLog::STATUS_SENT,
            'provider_message_id' => $result->messageId,
            'metadata' => $result->metadata,
            'sent_at' => now(),
        ]);

        Log::info('Email sent successfully', [
            'correlation_id' => $message->correlationId,
            'provider' => $result->providerUsed,
            'message_id' => $result->messageId,
        ]);
    }

    /**
     * Log a failed send.
     */
    private function logFailure(\Exception $e, EmailMessage $message): void
    {
        EmailLog::create([
            'correlation_id' => $message->correlationId,
            'provider' => $this->driver->getDriverName(),
            'driver' => get_class($this->driver),
            'from_email' => $message->from->email,
            'to_email' => $message->to[0]->email ?? '',
            'subject' => $message->subject,
            'status' => EmailLog::STATUS_FAILED,
            'error_message' => $e->getMessage(),
            'sent_at' => now(),
        ]);

        Log::error('Email send failed', [
            'correlation_id' => $message->correlationId,
            'provider' => $this->driver->getDriverName(),
            'error' => $e->getMessage(),
        ]);
    }
}
