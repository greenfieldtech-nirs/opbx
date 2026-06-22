<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class CallTrackingNotificationSettings extends Model
{
    use HasFactory;

    protected $table = 'call_tracking_notification_settings';

    protected $fillable = [
        'organization_id',
        'call_tracking_campaign_id',
        'webhook_url',
        'auth_method',
        'auth_secret',
        'auth_username',
        'enabled_events',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'enabled_events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = [
        'auth_method' => 'none',
        'enabled_events' => '["call.received","call.converted"]',
        'is_active' => true,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CallTrackingCampaign::class, 'call_tracking_campaign_id');
    }

    public function scopeForCampaign($query, int $campaignId)
    {
        return $query->where('call_tracking_campaign_id', $campaignId);
    }

    public function isEventEnabled(string $event): bool
    {
        return in_array($event, $this->enabled_events ?? [], true);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->webhook_url) && $this->is_active;
    }
}
