<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Email Log Model
 *
 * Tracks all transactional email sends for audit and debugging.
 */
class EmailLog extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'email_logs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'correlation_id',
        'provider',
        'driver',
        'from_email',
        'to_email',
        'subject',
        'status',
        'provider_message_id',
        'error_message',
        'metadata',
        'sent_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'metadata' => 'array',
        'sent_at' => 'datetime',
    ];

    /**
     * The possible statuses for an email.
     */
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_FAILED = 'failed';

    /**
     * Scope for successful sends.
     */
    public function scopeSuccessful($query)
    {
        return $query->whereIn('status', [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    /**
     * Scope for failed sends.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope for a specific provider.
     */
    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope for a specific correlation ID.
     */
    public function scopeForCorrelation($query, string $correlationId)
    {
        return $query->where('correlation_id', $correlationId);
    }

    /**
     * Scope for recent logs.
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
