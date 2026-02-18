<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailRecipient;
use App\Services\Email\Validation\ProviderConfigurationValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Email Send Test Command
 *
 * Test sending transactional emails via command line.
 */
class EmailSendTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:send-test
                            {--to= : Recipient email address}
                            {--subject=Test Email : Email subject}
                            {--provider= : Override the configured provider}
                            {--dry-run : Validate without sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test transactional email';

    /**
     * Execute the console command.
     */
    public function handle(TransactionalEmailInterface $emailService): int
    {
        $this->info('Transactional Email Test');
        $this->line('');

        // Check configuration
        $validator = new ProviderConfigurationValidator;
        $result = $validator->validate();

        if (! $result->isValid()) {
            $this->error('Configuration Error:');
            $this->error($result->getError());
            $this->line('');
            $this->info('Enabled providers: '.implode(', ', $result->getEnabledProviders()));

            return self::FAILURE;
        }

        $provider = $result->getProvider();

        if (! $provider) {
            $this->warn('No email provider is enabled.');
            $this->info('Set one of the EMAIL_*_ENABLED variables to true in your .env file.');

            return self::SUCCESS;
        }

        $this->info("Active provider: {$provider}");
        $this->line('');

        // Get recipient
        $to = $this->option('to');

        if (! $to) {
            $to = $this->ask('Enter recipient email address');
        }

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address: '.$to);

            return self::FAILURE;
        }

        // Check dry-run
        if ($this->option('dry-run')) {
            $this->info('Dry run - configuration is valid.');
            $this->info("Would send to: {$to}");

            return self::SUCCESS;
        }

        // Build message
        $subject = $this->option('subject');
        $fromEmail = Config::get('mail.from.address', 'noreply@example.com');
        $fromName = Config::get('mail.from.name', 'Test');

        $message = new EmailMessage(
            from: new EmailRecipient($fromEmail, $fromName),
            to: [new EmailRecipient($to)],
            subject: $subject,
            htmlContent: $this->getTestEmailHtml($to, $provider),
            textContent: $this->getTestEmailText($to, $provider),
        );

        $this->info('Sending test email...');
        $this->info("To: {$to}");
        $this->info("Subject: {$subject}");

        try {
            $result = $emailService->send($message);

            if ($result->success) {
                $this->info('');
                $this->info('✓ Email sent successfully!');
                $this->info("Provider: {$result->providerUsed}");
                $this->info("Message ID: {$result->messageId}");

                return self::SUCCESS;
            }

            $this->error('Failed to send email.');

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Error sending email: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Get HTML content for test email.
     */
    private function getTestEmailHtml(string $to, string $provider): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #3A29FF; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .footer { padding: 20px; text-align: center; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Test Email</h1>
        </div>
        <div class="content">
            <p>Hello,</p>
            <p>This is a test email from your OpBX system.</p>
            <ul>
                <li><strong>Recipient:</strong> {$to}</li>
                <li><strong>Provider:</strong> {$provider}</li>
                <li><strong>Time:</strong> " . now()->toDateTimeString() . "</li>
            </ul>
            <p>If you received this email, your transactional email configuration is working correctly!</p>
        </div>
        <div class="footer">
            <p>OpBX - Cloudonix PBX Platform</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get plain text content for test email.
     */
    private function getTestEmailText(string $to, string $provider): string
    {
        return <<<TEXT
Test Email

Hello,

This is a test email from your OpBX system.

Recipient: {$to}
Provider: {$provider}
Time: " . now()->toDateTimeString() . "

If you received this email, your transactional email configuration is working correctly!

---
OpBX - Cloudonix PBX Platform
TEXT;
    }
}
