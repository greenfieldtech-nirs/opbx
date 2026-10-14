<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PrunesByRetention;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

#[ScopedBy([OrganizationScope::class])]
class AutoDialerCallSession extends Model
{
    use HasFactory, PrunesByRetention;

    protected const RETENTION_KEY = 'auto_dialer_call_sessions_days';

    protected const RETENTION_COLUMN = 'created_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'campaign_id',
        'destination_id',
        'caller_id',
        'caller_did_id',
        'phone_number',
        'worker_id',
        'session_token',
        'call_id',
        'status',
        'disposition',
        'duration',
        'billsec',
        'recording_url',
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

    protected static function booted(): void
    {
        static::updated(fn (self $session) => $session->recordCallerIdOutcome());
    }

    /**
     * Count this call once in its pool caller ID's stats, on its first move to a final
     * status. Every path that finalises a session (webhooks, worker, AMD) passes here, and
     * a retried webhook or a later status change does not count it again.
     */
    private function recordCallerIdOutcome(): void
    {
        if (! $this->wasChanged('status') || ! $this->caller_did_id || ! in_array($this->status, ['completed', 'failed'], true)) {
            return;
        }

        if (! Cache::add("autodialer:session:{$this->id}:caller-id-stat", true, now()->addDays(7))) {
            return;
        }

        $stat = AutoDialerCallerIdStat::where('campaign_id', $this->campaign_id)
            ->where('did_number_id', $this->caller_did_id)
            ->first();

        $this->status === 'completed' ? $stat?->recordCompleted() : $stat?->recordFailed();
    }

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
