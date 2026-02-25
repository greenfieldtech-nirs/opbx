<?php

declare(strict_types=1);

namespace App\Services\Email\Validation;

use Illuminate\Support\Facades\Config;

/**
 * Provider Configuration Validator
 *
 * Validates that only one email provider is configured as enabled.
 */
class ProviderConfigurationValidator
{
    /**
     * Validate the email provider configuration.
     *
     * Ensures that only one provider is enabled at a time.
     */
    public function validate(): ValidationResult
    {
        $enabledProviders = [];
        $config = Config::get('services.transactional_email.providers', []);

        foreach ($config as $name => $provider) {
            if ($this->isEnabled($provider)) {
                $enabledProviders[] = $name;
            }
        }

        $count = count($enabledProviders);

        if ($count === 0) {
            return ValidationResult::success(null); // Email disabled
        }

        if ($count === 1) {
            return ValidationResult::success($enabledProviders[0]);
        }

        return ValidationResult::failure(
            message: sprintf(
                'Configuration Error: %d transactional email providers are enabled (%s). '.
                'Only ONE provider can be active at a time. '.
                'Please update your .env file and set only one EMAIL_*_ENABLED=true.',
                $count,
                implode(', ', $enabledProviders)
            ),
            enabledProviders: $enabledProviders
        );
    }

    /**
     * Check if a provider is enabled.
     */
    private function isEnabled(array $provider): bool
    {
        $enabled = $provider['enabled'] ?? false;

        // Handle various representations of boolean values
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
     * Get a list of all available providers.
     */
    public function getAvailableProviders(): array
    {
        return array_keys(Config::get('services.transactional_email.providers', []));
    }

    /**
     * Check if any provider is configured.
     */
    public function hasAnyProvider(): bool
    {
        $result = $this->validate();

        return $result->isValid() && $result->getProvider() !== null;
    }
}
