<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use App\Services\ApplicationConfig;
use App\Services\WebhookUrlResolver;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cloudonix integration settings for an organization.
 *
 * @property int $id
 * @property int $organization_id
 * @property string|null $domain_uuid
 * @property string|null $domain_name
 * @property string|null $domain_api_key
 * @property string|null $domain_requests_api_key
 * @property string|null $webhook_base_url
 * @property array<int, string>|null $embed_allowed_domains
 * @property int|null $voice_application_id
 * @property string|null $voice_application_uuid
 * @property string|null $voice_application_name
 * @property int $no_answer_timeout
 * @property string $call_recording_mode
 * @property string $recording_format
 * @property string|null $cloudonix_package
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Organization $organization
 */
#[ScopedBy([OrganizationScope::class])]
class CloudonixSettings extends Model
{
    use HasFactory;

    /**
     * Webhook-auth lookup by Cloudonix domain (`domain_name` or `domain_uuid`): the caller
     * is not authenticated yet, so the tenant scope is bypassed on purpose.
     */
    public static function forDomain(string $column, string $value): ?self
    {
        assert(in_array($column, ['domain_name', 'domain_uuid'], true));

        return OrganizationScope::bypass(fn () => static::where($column, $value)->first());
    }

    /**
     * Settings of an explicit organization, for contexts with no authenticated user
     * (voice webhooks, queue jobs, workers, console). Callers pass the organization id
     * they already trust, so the tenant scope is bypassed on purpose.
     */
    public static function forOrganization(?int $organizationId): ?self
    {
        return $organizationId === null
            ? null
            : OrganizationScope::bypass(fn () => static::where('organization_id', $organizationId)->first());
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cloudonix_settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'domain_uuid',
        'domain_name',
        'domain_api_key',
        'domain_requests_api_key',
        'webhook_base_url',
        'embed_allowed_domains',
        'voice_application_id',
        'voice_application_uuid',
        'voice_application_name',
        'no_answer_timeout',
        'call_recording_mode',
        'recording_format',
        'cloudonix_package',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domain_uuid' => 'string',
            'domain_api_key' => 'encrypted',
            'domain_requests_api_key' => 'encrypted',
            'no_answer_timeout' => 'integer',
            'embed_allowed_domains' => 'array',
        ];
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'domain_api_key',
        'domain_requests_api_key',
    ];

    /**
     * Get the organization that owns the settings.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the callback URL for Cloudonix webhooks.
     *
     * This URL is used by Cloudonix to send session update events.
     */
    public function getCallbackUrl(): string
    {
        // Use effective webhook base URL (considers application-level override)
        $baseUrl = rtrim($this->effective_webhook_base_url ?? config('app.url'), '/');

        return "{$baseUrl}/api/webhooks/cloudonix/session-update";
    }

    /**
     * Get the CDR webhook URL for Cloudonix.
     *
     * This URL is used by Cloudonix to send CDR (Call Detail Records) events.
     */
    public function getCdrUrl(): string
    {
        // Use effective webhook base URL (considers application-level override)
        $baseUrl = rtrim($this->effective_webhook_base_url ?? config('app.url'), '/');

        return "{$baseUrl}/api/webhooks/cloudonix/cdr";
    }

    /**
     * Get the voice route webhook URL for Cloudonix voice applications.
     *
     * This URL is used by Cloudonix voice applications to request call routing instructions.
     */
    public function getVoiceRouteUrl(): string
    {
        // Use effective webhook base URL (considers application-level override)
        $baseUrl = rtrim($this->effective_webhook_base_url ?? config('app.url'), '/');

        return "{$baseUrl}/api/voice/route";
    }

    /**
     * Get a masked version of the domain API key.
     *
     * Shows only the first 4 and last 4 characters, with *** as separator.
     * The *** pattern is used by the controller to detect masked values.
     */
    public function getMaskedDomainApiKey(): ?string
    {
        if (! $this->domain_api_key) {
            return null;
        }

        $key = $this->domain_api_key;
        $length = strlen($key);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 4).'***'.substr($key, -4);
    }

    /**
     * Get a masked version of the domain requests API key.
     *
     * Shows only the first 4 and last 4 characters, with *** as separator.
     * The *** pattern is used by the controller to detect masked values.
     */
    public function getMaskedDomainRequestsApiKey(): ?string
    {
        if (! $this->domain_requests_api_key) {
            return null;
        }

        $key = $this->domain_requests_api_key;
        $length = strlen($key);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 4).'***'.substr($key, -4);
    }

    /**
     * Check if Cloudonix integration is configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->domain_uuid) && ! empty($this->domain_api_key);
    }

    /**
     * Check if webhook authentication is enabled.
     */
    public function hasWebhookAuth(): bool
    {
        return ! empty($this->domain_requests_api_key);
    }

    /**
     * Get effective webhook base URL (considering application-level override)
     */
    public function getEffectiveWebhookBaseUrlAttribute(): ?string
    {
        // Same rule as WebhookUrlResolver::resolveWebhookBaseUrl(), without re-loading this row via the organization.
        return WebhookUrlResolver::shouldUseApplicationUrl()
            ? ApplicationConfig::getApplicationWebhookBaseUrl()
            : $this->webhook_base_url;
    }

    /**
     * Check if webhook URL is overridden at application level
     */
    public function isWebhookUrlOverridden(): bool
    {
        if (! $this->organization) {
            return false;
        }

        return WebhookUrlResolver::isWebhookUrlOverridden($this->organization);
    }

    /**
     * Get webhook URL details including override status
     */
    public function getWebhookUrlDetails(): array
    {
        if (! $this->organization) {
            return [
                'effective_url' => null,
                'application_url' => null,
                'organization_url' => null,
                'is_overridden' => false,
                'source' => 'organization',
            ];
        }

        return WebhookUrlResolver::getWebhookUrlDetails($this->organization);
    }
}
