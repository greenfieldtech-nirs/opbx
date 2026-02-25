<?php

declare(strict_types=1);

namespace App\Services\Email\Drivers;

use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailSendResult;
use App\Services\Email\Exceptions\DriverException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MailerLite Email Driver
 *
 * ⚠️ WARNING: MailerLite is primarily a marketing/email marketing platform,
 * not a transactional email service. It has limited transactional capabilities.
 *
 * Limitations:
 * - No native transactional email endpoint
 * - No attachment support
 * - Campaign-based sending (not instant)
 * - Stricter rate limits
 *
 * Use only as a last-resort fallback or for low-volume transactional emails.
 *
 * @see https://developers.mailerlite.com/docs/campaigns.html
 */
class MailerLiteDriver extends AbstractEmailDriver
{
    /**
     * Send an email via MailerLite.
     *
     * ⚠️ Creates a campaign and sends it immediately. This is NOT ideal
     * for transactional emails but works as a workaround.
     *
     * @throws \App\Services\Email\Exceptions\DriverException
     */
    public function send(EmailMessage $message): EmailSendResult
    {
        $this->validateConfig(['api_key']);

        // Check for attachment support
        if ($message->hasAttachments()) {
            throw new DriverException(
                'MailerLite does not support email attachments. '.
                'Please use a different provider (Mailgun, Mailjet, or SendInBlue).',
                $this->getDriverName()
            );
        }

        Log::warning('Using MailerLite for transactional email - this is not recommended', [
            'correlation_id' => $message->correlationId,
            'to' => $message->to[0]->email ?? 'unknown',
            'subject' => $message->subject,
        ]);

        try {
            // Step 1: Create a campaign
            $campaignResponse = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->config['api_key'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post('https://connect.mailerlite.com/api/campaigns', [
                    'type' => 'regular',
                    'name' => 'Transactional: '.$message->subject.' ('.uniqid().')',
                    'emails' => [
                        [
                            'subject' => $message->subject,
                            'from' => $message->from->email,
                            'from_name' => $message->from->name ?? $message->from->email,
                            'content' => $message->htmlContent ?? nl2br($message->textContent ?? ''),
                        ],
                    ],
                ]);

            if ($campaignResponse->failed()) {
                $error = $campaignResponse->json();
                throw new \Exception(
                    $error['message'] ?? 'MailerLite campaign creation failed',
                    $campaignResponse->status()
                );
            }

            $campaignData = $campaignResponse->json();
            $campaignId = $campaignData['data']['id'] ?? null;

            if (! $campaignId) {
                throw new \Exception('Failed to get campaign ID from MailerLite');
            }

            // Step 2: Schedule/send the campaign immediately
            $sendResponse = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->config['api_key'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post("https://connect.mailerlite.com/api/campaigns/{$campaignId}/send", [
                    'delivery' => 'instant',
                ]);

            if ($sendResponse->failed()) {
                // Try to cancel the campaign since we couldn't send it
                $this->cancelCampaign($campaignId);

                $error = $sendResponse->json();
                throw new \Exception(
                    $error['message'] ?? 'MailerLite campaign send failed',
                    $sendResponse->status()
                );
            }

            $this->logSend($message, 'sent', [
                'campaign_id' => $campaignId,
                'warning' => 'Used MailerLite - not recommended for transactional',
            ]);

            return EmailSendResult::success(
                providerUsed: $this->getDriverName(),
                messageId: (string) $campaignId,
                metadata: [
                    'mailerlite_campaign_id' => $campaignId,
                    'warning' => 'MailerLite is not ideal for transactional emails',
                ]
            );
        } catch (\Exception $e) {
            Log::error('MailerLite send failed', [
                'error' => $e->getMessage(),
                'correlation_id' => $message->correlationId,
            ]);

            throw $this->normalizeException($e);
        }
    }

    /**
     * Cancel a campaign (cleanup on failure).
     */
    private function cancelCampaign(string $campaignId): void
    {
        try {
            Http::withHeaders([
                'Authorization' => 'Bearer '.$this->config['api_key'],
            ])
                ->timeout(5)
                ->post("https://connect.mailerlite.com/api/campaigns/{$campaignId}/cancel");
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    /**
     * Check if driver supports attachments.
     */
    public function supportsAttachments(): bool
    {
        return false;
    }

    /**
     * Check if driver supports templates.
     */
    public function supportsTemplates(): bool
    {
        return false; // Limited template support via campaigns
    }

    /**
     * Get the driver name.
     */
    public function getDriverName(): string
    {
        return 'mailerlite';
    }

    /**
     * Perform a health check.
     */
    public function healthCheck(): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->config['api_key'],
            ])
                ->timeout(5)
                ->get('https://connect.mailerlite.com/api/subscribers');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
