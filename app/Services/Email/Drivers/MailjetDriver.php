<?php

declare(strict_types=1);

namespace App\Services\Email\Drivers;

use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailSendResult;
use App\Services\Email\Exceptions\DriverException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mailjet Email Driver
 *
 * Driver for sending emails via Mailjet API v3.1.
 *
 * @see https://dev.mailjet.com/email/guides/
 */
class MailjetDriver extends AbstractEmailDriver
{
    /**
     * Send an email via Mailjet.
     *
     * @throws DriverException
     */
    public function send(EmailMessage $message): EmailSendResult
    {
        $this->validateConfig(['key', 'secret']);

        $url = 'https://api.mailjet.com/v3.1/send';

        $this->logApiCall('POST', $url, [
            'correlation_id' => $message->correlationId,
            'to' => array_map(fn ($r) => $r->email, $message->to),
            'subject' => $message->subject,
        ]);

        try {
            $response = Http::withBasicAuth($this->config['key'], $this->config['secret'])
                ->timeout(30)
                ->acceptJson()
                ->post($url, $this->buildPayload($message));

            if ($response->failed()) {
                $this->logApiResponse($response, $message->correlationId, true);

                $error = $response->json();
                $errorMessage = $error['ErrorMessage'] ?? $error['ErrorInfo'] ?? 'Mailjet API error';
                throw new \Exception($errorMessage, $response->status());
            }

            $this->logApiResponse($response, $message->correlationId);

            $data = $response->json();
            $messageData = $data['Messages'][0] ?? [];

            $this->logSend($message, 'sent', [
                'message_id' => $messageData['To'][0]['MessageID'] ?? null,
            ]);

            return EmailSendResult::success(
                providerUsed: $this->getDriverName(),
                messageId: (string) ($messageData['To'][0]['MessageID'] ?? ''),
                metadata: [
                    'mailjet_message_uuid' => $messageData['To'][0]['MessageUUID'] ?? null,
                    'status' => $messageData['Status'] ?? null,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Mailjet send failed', [
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
            'Messages' => [
                [
                    'From' => [
                        'Email' => $message->from->email,
                        'Name' => $message->from->name ?? '',
                    ],
                    'To' => array_map(fn ($r) => [
                        'Email' => $r->email,
                        'Name' => $r->name ?? '',
                    ], $message->to),
                    'Subject' => $message->subject,
                    'TrackOpens' => $this->config['track_opens'] ?? true,
                    'TrackClicks' => $this->config['track_clicks'] ?? true,
                ],
            ],
        ];

        // Content
        if ($message->htmlContent) {
            $payload['Messages'][0]['HTMLPart'] = $message->htmlContent;
        }

        if ($message->textContent) {
            $payload['Messages'][0]['TextPart'] = $message->textContent;
        }

        // CC and BCC
        if (! empty($message->cc)) {
            $payload['Messages'][0]['Cc'] = array_map(fn ($r) => [
                'Email' => $r->email,
                'Name' => $r->name ?? '',
            ], $message->cc);
        }

        if (! empty($message->bcc)) {
            $payload['Messages'][0]['Bcc'] = array_map(fn ($r) => [
                'Email' => $r->email,
                'Name' => $r->name ?? '',
            ], $message->bcc);
        }

        // Headers
        if (! empty($message->headers)) {
            $payload['Messages'][0]['Headers'] = $message->headers;
        }

        // Custom ID (correlation)
        if ($message->correlationId) {
            $payload['Messages'][0]['CustomID'] = $message->correlationId;
        }

        // Template support
        if ($message->isTemplated()) {
            $payload['Messages'][0]['TemplateID'] = (int) $message->templateId;
            $payload['Messages'][0]['TemplateLanguage'] = true;
            $payload['Messages'][0]['Variables'] = $message->templateData;
        }

        // Attachments
        if (! empty($message->attachments)) {
            $payload['Messages'][0]['Attachments'] = array_map(fn ($a) => [
                'ContentType' => $a->mimeType ?? 'application/octet-stream',
                'Filename' => $a->filename,
                'Base64Content' => $a->getBase64Content(),
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
        return 'mailjet';
    }

    /**
     * Perform a health check.
     */
    public function healthCheck(): bool
    {
        try {
            $response = Http::withBasicAuth($this->config['key'], $this->config['secret'])
                ->timeout(5)
                ->get('https://api.mailjet.com/v3/REST/user');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
