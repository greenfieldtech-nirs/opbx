<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Application Configuration Service
 *
 * Manages application-level configuration for SaaS deployment mode.
 */
class ApplicationConfig
{
    /**
     * Application modes
     */
    public const MODE_DEVELOPMENT = 'development';

    public const MODE_PRODUCTION = 'production';

    /**
     * Get application mode
     */
    public static function getMode(): string
    {
        $mode = env('OPBX_APPLICATION_MODE', self::MODE_DEVELOPMENT);

        // Validate mode
        if (! in_array($mode, [self::MODE_DEVELOPMENT, self::MODE_PRODUCTION], true)) {
            return self::MODE_DEVELOPMENT;
        }

        return $mode;
    }

    /**
     * Check if application is in production mode
     */
    public static function isProduction(): bool
    {
        return self::getMode() === self::MODE_PRODUCTION;
    }

    /**
     * Check if application is in development mode
     */
    public static function isDevelopment(): bool
    {
        return self::getMode() === self::MODE_DEVELOPMENT;
    }

    /**
     * Get application-level webhook base URL
     */
    public static function getApplicationWebhookBaseUrl(): ?string
    {
        $url = env('OPBX_APPLICATION_WEBHOOK_BASEURL');

        if (empty($url)) {
            return null;
        }

        // Validate URL format
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // Ensure URL has http or https scheme
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * Check if application webhook URL is configured
     */
    public static function hasApplicationWebhookBaseUrl(): bool
    {
        return self::getApplicationWebhookBaseUrl() !== null;
    }

    /**
     * Check if configuration is valid
     */
    public static function isValidConfiguration(): bool
    {
        // Development mode is always valid
        if (self::isDevelopment()) {
            return true;
        }

        // Production mode requires webhook URL
        return self::hasApplicationWebhookBaseUrl();
    }

    /**
     * Get configuration warnings
     *
     * @return array<string>
     */
    public static function getConfigurationWarnings(): array
    {
        $warnings = [];

        if (self::isProduction() && ! self::hasApplicationWebhookBaseUrl()) {
            $warnings[] = 'Production mode requires OPBX_APPLICATION_WEBHOOK_BASEURL to be set';
        }

        $url = env('OPBX_APPLICATION_WEBHOOK_BASEURL');
        if (! empty($url) && ! self::hasApplicationWebhookBaseUrl()) {
            $warnings[] = 'OPBX_APPLICATION_WEBHOOK_BASEURL is set but invalid (must be valid http/https URL)';
        }

        return $warnings;
    }

    /**
     * Check if webhook fields should be hidden in UI
     */
    public static function shouldHideWebhookFields(): bool
    {
        return self::hasApplicationWebhookBaseUrl();
    }

    /**
     * Get configuration summary for API response
     */
    public static function getConfigurationSummary(): array
    {
        return [
            'mode' => self::getMode(),
            'is_production' => self::isProduction(),
            'has_application_webhook_url' => self::hasApplicationWebhookBaseUrl(),
            'is_valid_configuration' => self::isValidConfiguration(),
            'warnings' => self::getConfigurationWarnings(),
            'hide_webhook_fields' => self::shouldHideWebhookFields(),
            'recaptcha' => [
                'enabled' => config('services.recaptcha.enabled', false),
                'site_key' => config('services.recaptcha.site_key'),
            ],
        ];
    }
}
