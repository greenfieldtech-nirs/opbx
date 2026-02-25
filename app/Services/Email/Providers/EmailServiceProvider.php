<?php

declare(strict_types=1);

namespace App\Services\Email\Providers;

use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\Email\Drivers\MailerLiteDriver;
use App\Services\Email\Drivers\MailgunDriver;
use App\Services\Email\Drivers\MailjetDriver;
use App\Services\Email\Drivers\SendInBlueDriver;
use App\Services\Email\Exceptions\InvalidConfigurationException;
use App\Services\Email\Exceptions\MultipleProvidersException;
use App\Services\Email\TransactionalEmailService;
use App\Services\Email\Validation\ProviderConfigurationValidator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Email Service Provider
 *
 * Registers the transactional email service and validates configuration.
 */
class EmailServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register individual drivers
        $this->registerDrivers();

        // Register the main service
        $this->app->singleton(TransactionalEmailInterface::class, function ($app) {
            $config = Config::get('services.transactional_email');

            // Check if there's a configuration error
            if (isset($config['error'])) {
                throw new InvalidConfigurationException($config['error']);
            }

            $provider = $config['provider'] ?? 'mailgun';
            $driver = $this->getDriver($provider);

            return new TransactionalEmailService($driver, $config);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->validateConfiguration();
    }

    /**
     * Register the email drivers.
     */
    private function registerDrivers(): void
    {
        $this->app->singleton(MailgunDriver::class, function () {
            return new MailgunDriver(
                Config::get('services.transactional_email.providers.mailgun', [])
            );
        });

        $this->app->singleton(MailjetDriver::class, function () {
            return new MailjetDriver(
                Config::get('services.transactional_email.providers.mailjet', [])
            );
        });

        $this->app->singleton(MailerLiteDriver::class, function () {
            return new MailerLiteDriver(
                Config::get('services.transactional_email.providers.mailerlite', [])
            );
        });

        $this->app->singleton(SendInBlueDriver::class, function () {
            return new SendInBlueDriver(
                Config::get('services.transactional_email.providers.sendinblue', [])
            );
        });
    }

    /**
     * Get the appropriate driver instance.
     *
     * @throws InvalidConfigurationException
     */
    private function getDriver(string $provider): TransactionalEmailInterface
    {
        return match ($provider) {
            'mailgun' => $this->app->make(MailgunDriver::class),
            'mailjet' => $this->app->make(MailjetDriver::class),
            'mailerlite' => $this->app->make(MailerLiteDriver::class),
            'sendinblue' => $this->app->make(SendInBlueDriver::class),
            default => throw new InvalidConfigurationException(
                "Unknown email provider: {$provider}",
                'services.transactional_email.provider'
            ),
        };
    }

    /**
     * Validate the email configuration.
     */
    private function validateConfiguration(): void
    {
        $validator = new ProviderConfigurationValidator;
        $result = $validator->validate();

        if (! $result->isValid()) {
            // Store error in config for display
            Config::set('services.transactional_email.error', $result->getError());
            Config::set('services.transactional_email.enabled_providers', $result->getEnabledProviders());

            Log::critical('Email configuration error: '.$result->getError(), [
                'enabled_providers' => $result->getEnabledProviders(),
            ]);

            // In development, throw exception immediately
            if ($this->app->environment('local', 'development')) {
                throw new MultipleProvidersException(
                    $result->getEnabledProviders(),
                    $result->getError()
                );
            }
        }
    }
}
