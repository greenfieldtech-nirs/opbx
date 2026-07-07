<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailRecipient;
use App\Services\Email\Exceptions\InvalidConfigurationException;
use App\Services\Email\Validation\ProviderConfigurationValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Test Email Configuration Command
 *
 * Sends a test transactional email to validate provider configuration.
 */
class TestEmailConfiguration extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'email:test
                            {email : Recipient email address}
                            {--provider= : Provider to test (mailgun, mailjet, mailerlite, sendinblue)}
                            {--sync : Send synchronously instead of queueing}';

    /**
     * The console command description.
     */
    protected $description = 'Send a test email to validate provider configuration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");

            return self::FAILURE;
        }

        $provider = $this->option('provider');

        try {
            if ($provider) {
                $this->prepareProviderOverride($provider);
            } else {
                $validator = new ProviderConfigurationValidator;
                $result = $validator->validate();

                if (! $result->isValid()) {
                    $this->error('Configuration error:');
                    $this->error($result->getError());

                    return self::FAILURE;
                }

                $provider = $result->getProvider();

                if (! $provider) {
                    $this->warn('No email provider is enabled.');
                    $this->info('Set one of the EMAIL_*_ENABLED variables to true in your .env file.');

                    return self::SUCCESS;
                }
            }

            $emailService = app(TransactionalEmailInterface::class);
            $message = $this->buildTestMessage($email);

            if ($this->option('sync')) {
                return $this->sendSync($emailService, $message, $provider);
            }

            return $this->sendAsync($emailService, $message, $provider);
        } catch (InvalidConfigurationException $e) {
            $this->error('Configuration error: '.$e->getMessage());

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Failed to send test email: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Temporarily set the active provider for this command.
     *
     * @throws InvalidConfigurationException
     */
    private function prepareProviderOverride(string $provider): void
    {
        $config = Config::get('services.transactional_email');
        $providerConfig = $config['providers'][$provider] ?? null;

        if (! $providerConfig) {
            throw new InvalidConfigurationException("Unknown email provider: {$provider}");
        }

        if (! $this->isProviderEnabled($providerConfig['enabled'] ?? false)) {
            throw new InvalidConfigurationException("Provider {$provider} is not enabled.");
        }

        // Clear any global configuration error so the chosen provider can be resolved.
        Config::set('services.transactional_email.error', null);
        Config::set('services.transactional_email.provider', $provider);
    }

    /**
     * Check if a provider is enabled.
     */
    private function isProviderEnabled(mixed $enabled): bool
    {
        if (is_bool($enabled)) {
            return $enabled;
        }

        if (is_string($enabled)) {
            return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_int($enabled)) {
            return $enabled === 1;
        }

        return false;
    }

    /**
     * Build the test email message.
     */
    private function buildTestMessage(string $email): EmailMessage
    {
        $fromEmail = Config::get('mail.from.address', 'noreply@example.com');
        $fromName = Config::get('mail.from.name', 'OPBX');

        return new EmailMessage(
            from: new EmailRecipient($fromEmail, $fromName),
            to: [new EmailRecipient($email)],
            subject: 'OPBX Email Test',
            htmlContent: 'This is a test email from OPBX.',
        );
    }

    /**
     * Send the test email synchronously.
     */
    private function sendSync(TransactionalEmailInterface $emailService, EmailMessage $message, string $provider): int
    {
        $this->info("Sending synchronous test email via {$provider}...");

        $result = $emailService->send($message);

        if ($result->success) {
            $this->info('✓ Test email sent successfully');
            $this->info("Provider: {$result->providerUsed}");
            $this->info("Message ID: {$result->messageId}");

            return self::SUCCESS;
        }

        $this->error('Test email failed: '.($result->errorMessage ?? 'Unknown error'));

        return self::FAILURE;
    }

    /**
     * Queue the test email.
     */
    private function sendAsync(TransactionalEmailInterface $emailService, EmailMessage $message, string $provider): int
    {
        $queue = Config::get('services.transactional_email.queue', 'default');
        $jobId = $emailService->sendAsync($message);

        $this->info("Queued test email job on queue: {$queue}");
        $this->info("Provider: {$provider}");
        $this->info("Job ID: {$jobId}");

        return self::SUCCESS;
    }
}
