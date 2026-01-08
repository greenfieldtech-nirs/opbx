<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property int|null $voice_application_id
 * @property string|null $voice_application_uuid
 * @property string|null $voice_application_name
 * @property int $no_answer_timeout
 * @property string $recording_format
 * @property string|null $cloudonix_package
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Organization $organization
 */
class CloudonixSettings extends Model
{
    use HasFactory;

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
        'voice_application_id',
        'voice_application_uuid',
        'voice_application_name',
        'no_answer_timeout',
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
        // Use custom webhook base URL if configured, otherwise fall back to APP_URL
        $baseUrl = !empty($this->webhook_base_url)
            ? rtrim($this->webhook_base_url, '/')
            : rtrim(config('app.url'), '/');

        return "{$baseUrl}/api/webhooks/cloudonix/session-update";
    }

    /**
     * Get the CDR webhook URL for Cloudonix.
     *
     * This URL is used by Cloudonix to send CDR (Call Detail Records) events.
     */
    public function getCdrUrl(): string
    {
        // Use custom webhook base URL if configured, otherwise fall back to APP_URL
        $baseUrl = !empty($this->webhook_base_url)
            ? rtrim($this->webhook_base_url, '/')
            : rtrim(config('app.url'), '/');

        return "{$baseUrl}/api/webhooks/cloudonix/cdr";
    }

    /**
     * Get a masked version of the domain API key.
     *
     * Shows only the first 4 and last 4 characters.
     */
    public function getMaskedDomainApiKey(): ?string
    {
        if (!$this->domain_api_key) {
            return null;
        }

        $key = $this->domain_api_key;
        $length = strlen($key);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 4) . str_repeat('*', $length - 8) . substr($key, -4);
    }

    /**
     * Get a masked version of the domain requests API key.
     *
     * Shows only the first 4 and last 4 characters.
     */
    public function getMaskedDomainRequestsApiKey(): ?string
    {
        if (!$this->domain_requests_api_key) {
            return null;
        }

        $key = $this->domain_requests_api_key;
        $length = strlen($key);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 4) . str_repeat('*', $length - 8) . substr($key, -4);
    }

    /**
     * Check if Cloudonix integration is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->domain_uuid) && !empty($this->domain_api_key);
    }

    /**
     * Check if webhook authentication is enabled.
     */
    public function hasWebhookAuth(): bool
    {
        return !empty($this->domain_requests_api_key);
    }
}
