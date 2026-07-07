<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CallTrackingCampaignStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class CallTrackingNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'call_tracking_campaign_id',
        'did_number_id',
        'friendly_name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => CallTrackingCampaignStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CallTrackingCampaign::class, 'call_tracking_campaign_id');
    }

    public function did(): BelongsTo
    {
        return $this->belongsTo(DidNumber::class, 'did_number_id');
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', CallTrackingCampaignStatus::ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === CallTrackingCampaignStatus::ACTIVE;
    }
}
