<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Call Notifications Settings Model
 *
 * Stores webhook notification configuration for organizations.
 */
#[ScopedBy([OrganizationScope::class])]
class CallNotificationsSettings extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'call_notifications_settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'organization_id',
        'webhook_url',
        'auth_method',
        'auth_secret',
        'auth_username',
        'retry_attempts',
        'retry_backoff_seconds',
        'request_timeout_seconds',
        'enabled_events',
        'rate_limit_per_minute',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'auth_method' => 'string',
        'retry_attempts' => 'integer',
        'retry_backoff_seconds' => 'integer',
        'request_timeout_seconds' => 'integer',
        'enabled_events' => 'array',
        'rate_limit_per_minute' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'auth_method' => 'none',
        'retry_attempts' => 3,
        'retry_backoff_seconds' => 60,
        'request_timeout_seconds' => 30,
        'enabled_events' => '["new","ringing","connected","answered","busy","cancel","failed","congestion"]',
        'rate_limit_per_minute' => 500,
        'is_active' => true,
    ];

    /**
     * Get the organization that owns these notification settings.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope a query to only include records for a specific organization.
     * Note: This is in addition to the global OrganizationScope.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to only include active settings.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if a specific event type is enabled.
     */
    public function isEventEnabled(string $event): bool
    {
        return in_array($event, $this->enabled_events ?? [], true);
    }

    /**
     * Check if settings are properly configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->webhook_url) && $this->is_active;
    }

    /**
     * Get authentication headers for webhook requests.
     *
     * @return array<string, string>
     */
    public function getAuthHeaders(array $payload): array
    {
        $headers = [];

        switch ($this->auth_method) {
            case 'bearer_token':
                $headers['Authorization'] = 'Bearer '.($this->auth_secret ?? '');
                break;

            case 'basic_auth':
                $credentials = base64_encode(($this->auth_username ?? '').':'.($this->auth_secret ?? ''));
                $headers['Authorization'] = 'Basic '.$credentials;
                break;

            case 'none':
            default:
                // No authentication headers
                break;
        }

        return $headers;
    }
}
