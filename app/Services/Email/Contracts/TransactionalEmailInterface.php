<?php

declare(strict_types=1);

namespace App\Services\Email\Contracts;

use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailSendResult;

/**
 * Transactional Email Interface
 *
 * Defines the contract for all transactional email service providers.
 * Implementations must provide a unified interface for sending transactional emails.
 */
interface TransactionalEmailInterface
{
    /**
     * Send an email synchronously.
     *
     * @param  EmailMessage  $message  The email message to send
     * @return EmailSendResult The result of the send operation
     *
     * @throws \App\Services\Email\Exceptions\EmailException When sending fails
     */
    public function send(EmailMessage $message): EmailSendResult;

    /**
     * Send an email asynchronously via queue.
     *
     * @param  EmailMessage  $message  The email message to send
     * @return string The job ID for tracking
     */
    public function sendAsync(EmailMessage $message): string;

    /**
     * Check if the driver supports attachments.
     *
     * @return bool True if attachments are supported
     */
    public function supportsAttachments(): bool;

    /**
     * Check if the driver supports templates.
     *
     * @return bool True if templates are supported
     */
    public function supportsTemplates(): bool;

    /**
     * Get the driver name identifier.
     *
     * @return string The driver name (e.g., 'mailgun', 'mailjet')
     */
    public function getDriverName(): string;

    /**
     * Check if the driver is healthy and configured correctly.
     *
     * @return bool True if the driver is ready to send emails
     */
    public function healthCheck(): bool;
}
