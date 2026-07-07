<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class CallTrackingNotificationLog extends Model
{
    use HasFactory;

    protected $table = 'call_tracking_notification_logs';

    protected $fillable = [
        'organization_id',
        'call_tracking_campaign_id',
        'call_id',
        'event_id',
        'event_type',
        'webhook_url',
        'request_payload',
        'request_headers',
        'response_body',
        'response_headers',
        'response_status_code',
        'response_time_ms',
        'is_success',
        'attempt_number',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'request_headers' => 'array',
            'response_headers' => 'array',
            'is_success' => 'boolean',
            'response_status_code' => 'integer',
            'response_time_ms' => 'integer',
            'attempt_number' => 'integer',
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
}
