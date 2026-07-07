<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class CallTrackingSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'call_tracking_campaign_id',
        'call_tracking_number_id',
        'did_number_id',
        'call_id',
        'session_id',
        'caller_number',
        'caller_country',
        'called_number',
        'source',
        'medium',
        'campaign_name',
        'disposition',
        'duration',
        'billsec',
        'is_answered',
        'is_converted',
        'conversion_value',
        'started_at',
        'answered_at',
        'ended_at',
        'raw_cdr',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'billsec' => 'integer',
            'is_answered' => 'boolean',
            'is_converted' => 'boolean',
            'conversion_value' => 'decimal:4',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'raw_cdr' => 'array',
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

    public function trackingNumber(): BelongsTo
    {
        return $this->belongsTo(CallTrackingNumber::class, 'call_tracking_number_id');
    }

    public function did(): BelongsTo
    {
        return $this->belongsTo(DidNumber::class, 'did_number_id');
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
