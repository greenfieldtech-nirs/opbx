<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Call Notification Log Model
 *
 * Tracks webhook delivery attempts for call notifications.
 */
class CallNotificationLog extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'call_notification_logs';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'organization_id',
        'call_session_token',
        'event_id',
        'event_type',
        'status',
        'webhook_url',
        'request_payload',
        'response_status_code',
        'response_body',
        'response_time_ms',
        'attempt_number',
        'is_success',
        'error_message',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'request_payload' => 'array',
        'response_status_code' => 'integer',
        'response_time_ms' => 'integer',
        'attempt_number' => 'integer',
        'is_success' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * Get the organization that owns this log entry.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope query to only include logs for a specific organization.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to only include logs for a specific session.
     */
    public function scopeForSession($query, string $sessionToken)
    {
        return $query->where('call_session_token', $sessionToken);
    }

    /**
     * Scope query to only include successful deliveries.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('is_success', true);
    }

    /**
     * Scope query to only include failed deliveries.
     */
    public function scopeFailed($query)
    {
        return $query->where('is_success', false);
    }

    /**
     * Get recent logs within specified minutes.
     */
    public function scopeRecent($query, int $minutes = 60)
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Mark this log entry as successful.
     */
    public function markAsSuccess(int $statusCode, ?string $responseBody, int $responseTimeMs): void
    {
        $this->update([
            'is_success' => true,
            'response_status_code' => $statusCode,
            'response_body' => $responseBody,
            'response_time_ms' => $responseTimeMs,
        ]);
    }

    /**
     * Mark this log entry as failed.
     */
    public function markAsFailed(int $statusCode, ?string $responseBody, string $errorMessage, int $responseTimeMs): void
    {
        $this->update([
            'is_success' => false,
            'response_status_code' => $statusCode,
            'response_body' => $responseBody,
            'error_message' => $errorMessage,
            'response_time_ms' => $responseTimeMs,
        ]);
    }
}
