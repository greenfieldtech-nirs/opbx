<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;

/**
 * Webhook URL Resolver
 *
 * Resolves the effective webhook URLs for an organization,
 * taking into account application-level overrides in SaaS mode.
 */
class WebhookUrlResolver
{
    /**
     * Check if application-level webhook URL should be used
     */
    public static function shouldUseApplicationUrl(): bool
    {
        return ApplicationConfig::hasApplicationWebhookBaseUrl();
    }

    /**
     * Resolve webhook base URL for an organization
     *
     * Returns application-level URL if configured, otherwise organization's URL.
     */
    public static function resolveWebhookBaseUrl(Organization $organization): ?string
    {
        // Application-level override takes precedence
        if (self::shouldUseApplicationUrl()) {
            return ApplicationConfig::getApplicationWebhookBaseUrl();
        }

        // Fall back to organization's configured URL
        return $organization->cloudonixSettings?->webhook_base_url;
    }

    /**
     * Get all effective webhook URLs for an organization
     *
     * Returns an array of webhook URLs with their effective values.
     */
    public static function getEffectiveWebhookUrls(Organization $organization): array
    {
        $baseUrl = self::resolveWebhookBaseUrl($organization);

        if (! $baseUrl) {
            return [
                'base_url' => null,
                'call_initiated' => null,
                'call_status' => null,
                'session_update' => null,
                'cdr' => null,
                'voice_route' => null,
                'ivr_input' => null,
                'ring_group_callback' => null,
            ];
        }

        // Build all webhook endpoints
        return [
            'base_url' => $baseUrl,
            'call_initiated' => $baseUrl.'/api/webhooks/cloudonix/call-initiated',
            'call_status' => $baseUrl.'/api/webhooks/cloudonix/call-status',
            'session_update' => $baseUrl.'/api/webhooks/cloudonix/session-update',
            'cdr' => $baseUrl.'/api/webhooks/cloudonix/cdr',
            'voice_route' => $baseUrl.'/api/voice/route',
            'ivr_input' => $baseUrl.'/api/voice/ivr-input',
            'ring_group_callback' => $baseUrl.'/api/voice/ring-group-callback',
        ];
    }

    /**
     * Check if webhook URL is overridden for an organization
     */
    public static function isWebhookUrlOverridden(Organization $organization): bool
    {
        return self::shouldUseApplicationUrl() &&
               $organization->cloudonixSettings?->webhook_base_url !== null;
    }

    /**
     * Get webhook URL configuration details
     *
     * Returns information about the webhook URL source and override status.
     */
    public static function getWebhookUrlDetails(Organization $organization): array
    {
        $appUrl = ApplicationConfig::getApplicationWebhookBaseUrl();
        $orgUrl = $organization->cloudonixSettings?->webhook_base_url;
        $effectiveUrl = self::resolveWebhookBaseUrl($organization);

        return [
            'effective_url' => $effectiveUrl,
            'application_url' => $appUrl,
            'organization_url' => $orgUrl,
            'is_overridden' => self::isWebhookUrlOverridden($organization),
            'source' => $appUrl ? 'application' : 'organization',
        ];
    }
}
