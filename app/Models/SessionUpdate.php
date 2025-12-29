<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Session Update Model
 *
 * Stores real-time session update events from Cloudonix for debugging and monitoring.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $session_id
 * @property string $event_id
 * @property int $domain_id
 * @property string $domain
 * @property int $subscriber_id
 * @property int|null $outgoing_subscriber_id
 * @property string $caller_id
 * @property string $destination
 * @property string $direction
 * @property string $status
 * @property \Carbon\Carbon $session_created_at
 * @property \Carbon\Carbon $session_modified_at
 * @property int|null $call_start_time
 * @property \Carbon\Carbon|null $start_time
 * @property int|null $call_answer_time
 * @property \Carbon\Carbon|null $answer_time
 * @property int|null $time_limit
 * @property string|null $vapp_server
 * @property string $action
 * @property string $reason
 * @property string|null $last_error
 * @property array $call_ids
 * @property array $profile
 * @property \Carbon\Carbon $processed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Organization $organization
 */
class SessionUpdate extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'organization_id',
        'session_id',
        'event_id',
        'domain_id',
        'domain',
        'subscriber_id',
        'outgoing_subscriber_id',
        'caller_id',
        'destination',
        'direction',
        'status',
        'session_created_at',
        'session_modified_at',
        'call_start_time',
        'start_time',
        'call_answer_time',
        'answer_time',
        'time_limit',
        'vapp_server',
        'action',
        'reason',
        'last_error',
        'call_ids',
        'profile',
        'processed_at',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'session_created_at' => 'datetime',
            'session_modified_at' => 'datetime',
            'start_time' => 'datetime',
            'answer_time' => 'datetime',
            'processed_at' => 'datetime',
            'call_ids' => 'array',
            'profile' => 'array',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new OrganizationScope());
    }

    /**
     * Get the organization that owns the session update.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope a query to only include session updates for a specific organization.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope a query to only include session updates for a specific session.
     */
    public function scopeForSession($query, int $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Scope a query to only include session updates with a specific status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include session updates in a specific direction.
     */
    public function scopeWithDirection($query, string $direction)
    {
        return $query->where('direction', $direction);
    }

    /**
     * Scope a query to search by caller ID or destination.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('caller_id', 'like', "%{$search}%")
              ->orWhere('destination', 'like', "%{$search}%");
        });
    }
}