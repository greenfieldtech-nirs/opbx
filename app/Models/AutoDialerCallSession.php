<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class AutoDialerCallSession extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'campaign_id',
        'destination_id',
        'session_token',
        'call_id',
        'status',
        'initiated_at',
        'answered_at',
        'completed_at',
        'amd_result',
        'amd_confidence',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'initiated_at' => 'datetime',
        'answered_at' => 'datetime',
        'completed_at' => 'datetime',
        'amd_confidence' => 'decimal:2',
    ];

    /**
     * Get the campaign that owns the session.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AutoDialerCampaign::class, 'campaign_id');
    }

    /**
     * Get the destination that owns the session.
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(AutoDialerDestination::class, 'destination_id');
    }

    /**
     * Get the organization that owns the session.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope query to active sessions.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['initiated', 'ringing', 'answered']);
    }

    /**
     * Mark as answered.
     */
    public function markAsAnswered(): void
    {
        $this->update([
            'status' => 'answered',
            'answered_at' => now(),
        ]);
    }

    /**
     * Mark as completed.
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Set AMD result.
     */
    public function setAmdResult(string $result, ?float $confidence = null): void
    {
        $this->update([
            'amd_result' => $result,
            'amd_confidence' => $confidence,
        ]);
    }

    /**
     * Get duration in seconds.
     */
    public function getDuration(): int
    {
        if (! $this->initiated_at) {
            return 0;
        }

        $endTime = $this->completed_at ?? now();

        return (int) $this->initiated_at->diffInSeconds($endTime);
    }
}
