<?php

declare(strict_types=1);

namespace App\Services\Email\Exceptions;

/**
 * Multiple Providers Exception
 *
 * Thrown when multiple email providers are configured as enabled.
 */
class MultipleProvidersException extends InvalidConfigurationException
{
    /**
     * The list of enabled providers.
     */
    protected array $enabledProviders;

    /**
     * Create a new multiple providers exception.
     */
    public function __construct(
        array $enabledProviders,
        ?string $message = null,
        int $code = 0,
        ?\Exception $previous = null
    ) {
        $this->enabledProviders = $enabledProviders;

        $message ??= sprintf(
            'Configuration Error: %d transactional email providers are enabled (%s). '.
            'Only ONE provider can be active at a time. '.
            'Please update your .env file and set only one EMAIL_*_ENABLED=true.',
            count($enabledProviders),
            implode(', ', $enabledProviders)
        );

        parent::__construct($message, 'transactional_email.providers', $code, $previous);
    }

    /**
     * Get the enabled providers.
     */
    public function getEnabledProviders(): array
    {
        return $this->enabledProviders;
    }

    /**
     * Get a user-friendly fix suggestion.
     */
    public function getSuggestedFix(): string
    {
        return sprintf(
            'Set only one provider to ENABLED=true in your .env file. '.
            'Enabled providers found: %s',
            implode(', ', $this->enabledProviders)
        );
    }
}
