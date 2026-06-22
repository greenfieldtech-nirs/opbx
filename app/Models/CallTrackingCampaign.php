<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CallTrackingCampaignStatus;
use App\Enums\CallTrackingDestinationType;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([OrganizationScope::class])]
class CallTrackingCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'source',
        'medium',
        'description',
        'status',
        'destination_type',
        'destination_config',
        'conversion_rule',
        'google_ads_upload_enabled',
        'meta_upload_enabled',
    ];

    protected function casts(): array
    {
        return [
            'status' => CallTrackingCampaignStatus::class,
            'destination_type' => CallTrackingDestinationType::class,
            'destination_config' => 'array',
            'conversion_rule' => 'array',
            'google_ads_upload_enabled' => 'boolean',
            'meta_upload_enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function trackingNumbers(): HasMany
    {
        return $this->hasMany(CallTrackingNumber::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CallTrackingSession::class);
    }

    public function notificationSettings(): HasMany
    {
        return $this->hasMany(CallTrackingNotificationSettings::class);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', CallTrackingCampaignStatus::ACTIVE);
    }

    public function scopeWithStatus($query, CallTrackingCampaignStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('source', 'like', '%'.$term.'%')
                ->orWhere('medium', 'like', '%'.$term.'%');
        });
    }

    public function isActive(): bool
    {
        return $this->status === CallTrackingCampaignStatus::ACTIVE;
    }

    public function getForwardTo(): ?string
    {
        if ($this->destination_type !== CallTrackingDestinationType::FORWARD) {
            return null;
        }

        return $this->destination_config['forward_to'] ?? null;
    }

    public function getDestinationId(string $key): ?int
    {
        return isset($this->destination_config[$key])
            ? (int) $this->destination_config[$key]
            : null;
    }
}
