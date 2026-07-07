<?php

declare(strict_types=1);

namespace App\Services\Email\Drivers;

use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailSendResult;
use App\Services\Email\Exceptions\DriverException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SendInBlue (Brevo) Email Driver
 *
 * Driver for sending emails via SendInBlue/Brevo API.
 *
 * @see https://developers.brevo.com/reference/sendtransacemail
 */
class SendInBlueDriver extends AbstractEmailDriver
{
    /**
     * Send an email via SendInBlue/Brevo.
     *
     * @throws DriverException
     */
    public function send(EmailMessage $message): EmailSendResult
    {
        $this->validateConfig(['api_key']);

        $url = 'https://api.brevo.com/v3/smtp/email';

        $this->logApiCall('POST', $url, [
            'correlation_id' => $message->correlationId,
            'to' => array_map(fn ($r) => $r->email, $message->to),
            'subject' => $message->subject,
        ]);

        try {
            $response = Http::withHeaders([
                'api-key' => $this->config['api_key'],
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post($url, $this->buildPayload($message));

            if ($response->failed()) {
                $this->logApiResponse($response, $message->correlationId, true);

                $error = $response->json();
                $errorMessage = $error['message'] ?? 'SendInBlue API error';
                throw new \Exception($errorMessage, $response->status());
            }

            $this->logApiResponse($response, $message->correlationId);

            $data = $response->json();

            $this->logSend($message, 'sent', [
                'message_id' => $data['messageId'] ?? null,
            ]);

            return EmailSendResult::success(
                providerUsed: $this->getDriverName(),
                messageId: $data['messageId'] ?? null,
                metadata: [
                    'brevo_message_id' => $data['messageId'] ?? null,
                ]
            );
        } catch (\Exception $e) {
            Log::error('SendInBlue send failed', [
                'error' => $e->getMessage(),
                'correlation_id' => $message->correlationId,
            ]);

            throw $this->normalizeException($e);
        }
    }

    /**
     * Build the API payload.
     */
    private function buildPayload(EmailMessage $message): array
    {
        $payload = [
            'sender' => [
                'email' => $message->from->email,
                'name' => $message->from->name ?? null,
            ],
            'to' => array_map(fn ($r) => [
                'email' => $r->email,
                'name' => $r->name ?? null,
            ], $message->to),
            'subject' => $message->subject,
            'trackOpens' => $this->config['track_opens'] ?? true,
            'trackClicks' => $this->config['track_clicks'] ?? true,
        ];

        // Remove null values from sender
        $payload['sender'] = array_filter($payload['sender']);

        // Content
        if ($message->htmlContent) {
            $payload['htmlContent'] = $message->htmlContent;
        }

        if ($message->textContent) {
            $payload['textContent'] = $message->textContent;
        }

        // CC and BCC
        if (! empty($message->cc)) {
            $payload['cc'] = array_map(fn ($r) => [
                'email' => $r->email,
                'name' => $r->name ?? null,
            ], $message->cc);
        }

        if (! empty($message->bcc)) {
            $payload['bcc'] = array_map(fn ($r) => [
                'email' => $r->email,
                'name' => $r->name ?? null,
            ], $message->bcc);
        }

        // Headers
        if (! empty($message->headers)) {
            $payload['headers'] = $message->headers;
        }

        // Tags (Brevo uses tags array)
        if (! empty($message->tags)) {
            $payload['tags'] = $message->tags;
        }

        // Correlation ID as custom header
        if ($message->correlationId) {
            $payload['headers'] ??= [];
            $payload['headers']['X-Correlation-Id'] = $message->correlationId;
        }

        // Template support
        if ($message->isTemplated()) {
            $payload['templateId'] = (int) $message->templateId;
            $payload['params'] = $message->templateData;

            // Remove content fields when using template
            unset($payload['htmlContent'], $payload['textContent']);
        }

        // Attachments
        if (! empty($message->attachments)) {
            $payload['attachment'] = array_map(fn ($a) => [
                'content' => $a->getBase64Content(),
                'name' => $a->filename,
            ], $message->attachments);
        }

        return $payload;
    }

    /**
     * Check if driver supports attachments.
     */
    public function supportsAttachments(): bool
    {
        return true;
    }

    /**
     * Check if driver supports templates.
     */
    public function supportsTemplates(): bool
    {
        return true;
    }

    /**
     * Get the driver name.
     */
    public function getDriverName(): string
    {
        return 'sendinblue';
    }

    /**
     * Perform a health check.
     */
    public function healthCheck(): bool
    {
        try {
            $response = Http::withHeaders([
                'api-key' => $this->config['api_key'],
            ])
                ->timeout(5)
                ->get('https://api.brevo.com/v3/account');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
