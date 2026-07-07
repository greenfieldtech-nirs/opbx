<?php

declare(strict_types=1);

namespace App\Services\Email\Drivers;

use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailRecipient;
use App\Services\Email\Exceptions\DriverException;
use App\Services\Email\Exceptions\InvalidConfigurationException;
use App\Services\Email\Jobs\SendTransactionalEmailJob;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $job = new SendTransactionalEmailJob($message, $this->getDriverName());
        $jobId = (string) Str::uuid();

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
     * Log an outgoing API request.
     */
    protected function logApiCall(string $method, string $url, array $context): void
    {
        Log::info('Email provider API request', array_merge(
            [
                'method' => $method,
                'url' => $this->redactUrl($url),
                'driver' => $this->getDriverName(),
            ],
            $this->redactPayload($context)
        ));
    }

    /**
     * Log an API response.
     */
    protected function logApiResponse(Response $response, string $correlationId, bool $isError = false): void
    {
        $body = $this->formatResponseBody($response, ! $isError);
        $payload = [
            'status' => $response->status(),
            'correlation_id' => $correlationId,
            'driver' => $this->getDriverName(),
            'body' => $body,
        ];

        if ($isError) {
            Log::error('Email provider API error response', $payload);

            return;
        }

        Log::info('Email provider API response', $payload);
    }

    /**
     * Format a response body for logging.
     */
    private function formatResponseBody(Response $response, bool $truncate): array|string
    {
        $body = $response->body();
        $decoded = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $body = $this->redactPayload($decoded);
        }

        if (! $truncate) {
            return $body;
        }

        $string = is_array($body) ? json_encode($body) : (string) $body;

        return $this->truncate($string, 2000);
    }

    /**
     * Redact sensitive values from a payload.
     */
    protected function redactPayload(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = $this->redactPayload($value);

                continue;
            }

            if (is_string($key) && in_array(strtolower($key), ['secret', 'key', 'api_key', 'apikey', 'password', 'token'], true)) {
                $redacted[$key] = '***REDACTED***';

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    /**
     * Redact secrets from a URL query string.
     */
    protected function redactUrl(string $url): string
    {
        return preg_replace('/((?:\?|&)(?:secret|key|api_key|apikey|password|token)=)[^&]*/i', '$1***REDACTED***', $url) ?? $url;
    }

    /**
     * Truncate a string to the given length.
     */
    protected function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length).'... [truncated]';
    }

    /**
     * Check if required configuration keys are present.
     *
     * @throws InvalidConfigurationException
     */
    protected function validateConfig(array $keys): void
    {
        foreach ($keys as $key) {
            if (empty($this->config[$key])) {
                Log::error('Email driver configuration missing required key', [
                    'driver' => $this->getDriverName(),
                    'missing_key' => $key,
                    'available_keys' => array_keys($this->config),
                ]);

                throw new InvalidConfigurationException(
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
