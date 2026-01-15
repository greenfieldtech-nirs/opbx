<?php

declare(strict_types=1);

namespace App\Services\Logging;

/**
 * Service for sanitizing sensitive data from logs.
 *
 * Provides utilities to remove or mask sensitive information before logging,
 * ensuring compliance with security standards and data protection regulations.
 *
 * Usage:
 * ```php
 * Log::info('User action', LogSanitizer::sanitizeArray($data));
 * ```
 */
class LogSanitizer
{
    /**
     * Keys that contain sensitive data and should be masked.
     *
     * @var array<string>
     */
    private const SENSITIVE_KEYS = [
        // Authentication & Authorization
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'bearer_token',
        'api_key',
        'api_token',
        'api_secret',
        'secret',
        'secret_key',
        'private_key',
        'public_key',

        // Webhook & Integration
        'webhook_secret',
        'webhook_token',
        'signature',
        'hmac',

        // SIP & VoIP
        'sip_password',
        'sip_secret',

        // Cloudonix Specific
        'domain_api_key',
        'domain_requests_api_key',
        'domain_cdr_auth_key',

        // Database & Cache
        'db_password',
        'redis_password',
        'cache_key',

        // Session & CSRF
        'session_id',
        'csrf_token',
        '_token',

        // Payment & PII
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
        'social_security',
        'tax_id',

        // HTTP Headers (when logged)
        'authorization',
        'cookie',
        'set-cookie',
    ];

    /**
     * Mask value to use for sensitive data.
     */
    private const MASK = '[REDACTED]';

    /**
     * Sanitize an array by removing or masking sensitive keys.
     *
     * @param  array<string, mixed>  $data  Data to sanitize
     * @param  bool  $deep  Whether to recursively sanitize nested arrays
     * @return array<string, mixed> Sanitized data
     */
    public static function sanitizeArray(array $data, bool $deep = true): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (self::isSensitiveKey($key)) {
                // Mask sensitive value
                $sanitized[$key] = self::MASK;
            } elseif ($deep && is_array($value)) {
                // Recursively sanitize nested arrays
                $sanitized[$key] = self::sanitizeArray($value, true);
            } else {
                // Keep non-sensitive values
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize HTTP headers by removing sensitive headers.
     *
     * @param  array<string, string|array<string>>  $headers  Headers to sanitize
     * @return array<string, string|array<string>> Sanitized headers
     */
    public static function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);

            if (in_array($lowerKey, ['authorization', 'cookie', 'set-cookie'], true)) {
                $sanitized[$key] = self::MASK;
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize a string by masking common sensitive patterns.
     *
     * Masks:
     * - Bearer tokens
     * - API keys starting with common prefixes
     * - Passwords in URLs
     *
     * @param  string  $text  Text to sanitize
     * @return string Sanitized text
     */
    public static function sanitizeString(string $text): string
    {
        // Mask Bearer tokens
        $text = preg_replace('/Bearer\s+[\w\-\.]+/i', 'Bearer [REDACTED]', $text);

        // Mask API keys (common patterns)
        $text = preg_replace('/\b(api[_-]?key|token)[=:]\s*[\w\-\.]+/i', '$1=[REDACTED]', $text);

        // Mask passwords in URLs
        $text = preg_replace('/:\/\/[^:]+:[^@]+@/', '://[REDACTED]:[REDACTED]@', $text);

        return $text;
    }

    /**
     * Check if a key is sensitive and should be masked.
     *
     * @param  string|int  $key  Key to check
     * @return bool True if key is sensitive
     */
    private static function isSensitiveKey(string|int $key): bool
    {
        // Skip numeric keys (array indices)
        if (is_int($key)) {
            return false;
        }
        
        $lowerKey = strtolower($key);

        // Check exact matches
        if (in_array($lowerKey, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        // Check if key contains sensitive substrings
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($lowerKey, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize a Cloudonix webhook payload.
     *
     * Removes sensitive fields specific to Cloudonix webhooks while
     * preserving data needed for debugging (call_id, event type, etc.)
     *
     * @param  array<string, mixed>  $payload  Webhook payload
     * @return array<string, mixed> Sanitized payload
     */
    public static function sanitizeWebhookPayload(array $payload): array
    {
        return self::sanitizeArray($payload, deep: true);
    }

    /**
     * Create a safe log context from a request.
     *
     * Extracts useful request information while excluding sensitive data.
     *
     * @param  \Illuminate\Http\Request  $request  Request to extract from
     * @return array<string, mixed> Safe log context
     */
    public static function requestContext($request): array
    {
        return [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'input' => self::sanitizeArray($request->except(self::SENSITIVE_KEYS)),
        ];
    }
}
