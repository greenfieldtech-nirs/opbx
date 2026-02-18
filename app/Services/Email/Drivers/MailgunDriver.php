<?php

declare(strict_types=1);

namespace App\Services\Email\Drivers;

use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailSendResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mailgun Email Driver
 *
 * Driver for sending emails via Mailgun API.
 *
 * @see https://documentation.mailgun.com/docs/mailgun/api-reference
 */
class MailgunDriver extends AbstractEmailDriver
{
    /**
     * Send an email via Mailgun.
     *
     * @throws \App\Services\Email\Exceptions\DriverException
     */
    public function send(EmailMessage $message): EmailSendResult
    {
        $this->validateConfig(['domain', 'secret']);

        try {
            $response = Http::withBasicAuth('api', $this->config['secret'])
                ->timeout(30)
                ->asMultipart()
                ->post($this->getApiUrl('/messages'), $this->buildPayload($message));

            if ($response->failed()) {
                throw new \Exception(
                    $response->json('message', 'Mailgun API error'),
                    $response->status()
                );
            }

            $data = $response->json();

            $this->logSend($message, 'sent', ['message_id' => $data['id'] ?? null]);

            return EmailSendResult::success(
                providerUsed: $this->getDriverName(),
                messageId: $data['id'] ?? null,
                metadata: [
                    'mailgun_id' => $data['id'] ?? null,
                    'message' => $data['message'] ?? null,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Mailgun send failed', [
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
            'from' => $this->formatAddress($message->from),
            'to' => implode(', ', $this->formatAddresses($message->to)),
            'subject' => $message->subject,
            'o:tracking-opens' => $this->config['track_opens'] ?? true,
            'o:tracking-clicks' => $this->config['track_clicks'] ?? true,
        ];

        // Content
        if ($message->htmlContent) {
            $payload['html'] = $message->htmlContent;
        }

        if ($message->textContent) {
            $payload['text'] = $message->textContent;
        }

        // CC and BCC
        if (! empty($message->cc)) {
            $payload['cc'] = implode(', ', $this->formatAddresses($message->cc));
        }

        if (! empty($message->bcc)) {
            $payload['bcc'] = implode(', ', $this->formatAddresses($message->bcc));
        }

        // Headers
        foreach ($message->headers as $name => $value) {
            $payload["h:{$name}"] = $value;
        }

        // Tags
        foreach ($message->tags as $index => $tag) {
            $payload["o:tag-{$index}"] = $tag;
        }

        // Template support
        if ($message->isTemplated()) {
            $payload['template'] = $message->templateId;
            foreach ($message->templateData as $key => $value) {
                $payload["v:{$key}"] = is_string($value) ? $value : json_encode($value);
            }
        }

        // Correlation ID as custom header
        if ($message->correlationId) {
            $payload['h:X-Correlation-Id'] = $message->correlationId;
        }

        // Attachments
        foreach ($message->attachments as $index => $attachment) {
            $payload["attachment[{$index}]"] = [
                'Content' => $attachment->getRawContent(),
                'filename' => $attachment->filename,
            ];
        }

        return $payload;
    }

    /**
     * Get the full API URL.
     */
    private function getApiUrl(string $path): string
    {
        $region = $this->config['region'] ?? 'us';
        $endpoint = $region === 'eu' ? 'api.eu.mailgun.net' : 'api.mailgun.net';

        return "https://{$endpoint}/v3/{$this->config['domain']}{$path}";
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
        return 'mailgun';
    }

    /**
     * Perform a health check.
     */
    public function healthCheck(): bool
    {
        try {
            $endpoint = $this->config['endpoint'] ?? 'api.mailgun.net';
            $response = Http::withBasicAuth('api', $this->config['secret'])
                ->timeout(5)
                ->get("https://{$endpoint}/v3/domains");

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
