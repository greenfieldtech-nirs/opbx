<?php

declare(strict_types=1);

namespace App\Services\AiAssistant;

use InvalidArgumentException;

/**
 * Builds WebSocket URLs from templates by substituting placeholders.
 *
 * This service handles secure URL construction for WebSocket-based AI providers.
 * It validates templates, substitutes variables, and ensures proper URL encoding.
 */
class WebSocketUrlBuilder
{
    /**
     * Allowed placeholder names for security.
     */
    private const ALLOWED_CONFIG_PLACEHOLDERS = [
        'bot_id',
        'auth_token',
        'api_key',
        'assistant_id',
        'agent_id',
        'app_id',
        'workspace_id',
        'project_id',
    ];

    /**
     * Cloudonix-provided parameter placeholders.
     */
    private const CLOUDONIX_PLACEHOLDERS = [
        'session',
        'from',
        'to',
    ];

    /**
     * Build a WebSocket URL from a template and parameters.
     *
     * @param  string  $template  URL template with {placeholder} syntax
     * @param  array<string, mixed>  $configValues  Provider-specific configuration values
     * @param  array<string, mixed>  $cloudonixParams  Runtime Cloudonix parameters
     * @return string Built WebSocket URL
     *
     * @throws InvalidArgumentException If template is invalid or contains disallowed placeholders
     */
    public function buildUrl(string $template, array $configValues, array $cloudonixParams): string
    {
        // Validate template format
        if (! str_starts_with($template, 'wss://')) {
            throw new InvalidArgumentException('WebSocket URL template must start with wss://');
        }

        // Extract all placeholders from template
        preg_match_all('/\{([a-z_]+)\}/', $template, $matches);
        $placeholders = $matches[1] ?? [];

        // Validate all placeholders are allowed
        foreach ($placeholders as $placeholder) {
            if (! $this->isAllowedPlaceholder($placeholder)) {
                throw new InvalidArgumentException("Disallowed placeholder: {{$placeholder}}");
            }
        }

        // Build URL by substituting placeholders
        $url = $template;

        // Substitute config placeholders
        foreach (self::ALLOWED_CONFIG_PLACEHOLDERS as $placeholder) {
            if (isset($configValues[$placeholder])) {
                $value = $configValues[$placeholder];
                $encodedValue = $this->encodeUrlComponent($value);
                $url = str_replace('{'.$placeholder.'}', $encodedValue, $url);
            }
        }

        // Substitute Cloudonix parameter placeholders
        foreach (self::CLOUDONIX_PLACEHOLDERS as $placeholder) {
            if (isset($cloudonixParams[$placeholder])) {
                $value = $cloudonixParams[$placeholder];
                $encodedValue = $this->encodeUrlComponent($value);
                $url = str_replace('{'.$placeholder.'}', $encodedValue, $url);
            }
        }

        // Check for any unsubstituted placeholders — all must be resolved
        if (preg_match('/\{([a-z_]+)\}/', $url, $match)) {
            throw new InvalidArgumentException("Template contains unsubstituted placeholder: {{$match[1]}}");
        }

        // Final validation
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Built URL is invalid');
        }

        return $url;
    }

    /**
     * Check if a placeholder name is allowed.
     */
    private function isAllowedPlaceholder(string $placeholder): bool
    {
        return in_array($placeholder, self::ALLOWED_CONFIG_PLACEHOLDERS)
            || in_array($placeholder, self::CLOUDONIX_PLACEHOLDERS);
    }

    /**
     * Encode a value for use in URL (path or query string).
     *
     * This ensures special characters are properly encoded but doesn't
     * double-encode already encoded characters.
     */
    private function encodeUrlComponent(mixed $value): string
    {
        $stringValue = (string) $value;

        // URL-encode the value
        // Use rawurlencode to encode spaces as %20 instead of +
        return rawurlencode($stringValue);
    }

    /**
     * Validate a URL template without building it.
     *
     * Checks that the template has valid format and only allowed placeholders.
     *
     * @param  string  $template  URL template to validate
     * @return bool True if template is valid
     */
    public function validateTemplate(string $template): bool
    {
        try {
            // Check basic format
            if (! str_starts_with($template, 'wss://')) {
                return false;
            }

            // Extract and validate placeholders
            preg_match_all('/\{([a-z_]+)\}/', $template, $matches);
            $placeholders = $matches[1] ?? [];

            foreach ($placeholders as $placeholder) {
                if (! $this->isAllowedPlaceholder($placeholder)) {
                    return false;
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract required placeholders from a template.
     *
     * Returns list of placeholder names that need to be provided.
     *
     * @param  string  $template  URL template
     * @return array<string> List of placeholder names
     */
    public function extractPlaceholders(string $template): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $template, $matches);

        return $matches[1] ?? [];
    }

    /**
     * Get list of allowed configuration placeholders.
     *
     * @return array<string>
     */
    public static function getAllowedConfigPlaceholders(): array
    {
        return self::ALLOWED_CONFIG_PLACEHOLDERS;
    }

    /**
     * Get list of Cloudonix parameter placeholders.
     *
     * @return array<string>
     */
    public static function getCloudonixPlaceholders(): array
    {
        return self::CLOUDONIX_PLACEHOLDERS;
    }
}
