<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DestinationStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class AutoDialerDestination extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'list_id',
        'phone_number',
        'description',
        'status',
        'dial_attempts',
        'last_session_token',
        'last_call_id',
        'last_dialed_at',
        'next_retry_at',
        'last_disposition',
        'duration',
        'billsec',
        'total_duration',
        'last_cdr_id',
        'last_error',
        'priority',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => DestinationStatus::class,
        'last_dialed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    /**
     * Get the list that owns the destination.
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(AutoDialerList::class, 'list_id');
    }

    /**
     * Get the campaign through the list.
     */
    public function campaign(): BelongsTo
    {
        return $this->list->belongsTo(AutoDialerCampaign::class, 'campaign_id');
    }

    /**
     * Get the organization that owns the destination.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope query to pending destinations.
     */
    public function scopePending($query)
    {
        return $query->where('status', DestinationStatus::PENDING);
    }

    /**
     * Scope query to completed destinations.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', DestinationStatus::COMPLETED);
    }

    /**
     * Scope query to failed destinations.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', DestinationStatus::FAILED);
    }

    /**
     * Scope query to invalid destinations.
     */
    public function scopeInvalid($query)
    {
        return $query->where('status', DestinationStatus::INVALID);
    }

    /**
     * Check if the destination can be dialed.
     */
    public function canDial(): bool
    {
        return $this->status->canDial();
    }

    /**
     * Increment dial attempts.
     */
    public function incrementDialAttempts(): void
    {
        $this->increment('dial_attempts');
    }

    /**
     * Mark as invalid.
     */
    public function markAsInvalid(string $error): void
    {
        $this->update([
            'status' => DestinationStatus::INVALID,
            'last_error' => $error,
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(?string $error = null): void
    {
        $this->update([
            'status' => DestinationStatus::FAILED,
            'last_error' => $error,
        ]);
    }

    /**
     * Mark as completed.
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => DestinationStatus::COMPLETED,
        ]);
    }
}
