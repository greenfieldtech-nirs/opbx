<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoDialerCallerIdStat extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'auto_dialer_caller_id_stats';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'campaign_id',
        'did_number_id',
        'total_calls',
        'completed_calls',
        'failed_calls',
        'last_used_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'total_calls' => 'integer',
        'completed_calls' => 'integer',
        'failed_calls' => 'integer',
        'last_used_at' => 'datetime',
    ];

    /**
     * Get the campaign that owns these statistics.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AutoDialerCampaign::class, 'campaign_id');
    }

    /**
     * Get the DID number for these statistics.
     */
    public function didNumber(): BelongsTo
    {
        return $this->belongsTo(DidNumber::class, 'did_number_id');
    }

    /**
     * Calculate the success rate percentage.
     */
    public function getSuccessRateAttribute(): float
    {
        if ($this->total_calls === 0) {
            return 0.0;
        }

        return round(($this->completed_calls / $this->total_calls) * 100, 2);
    }

    /**
     * Increment stats for a completed call.
     */
    public function recordCompleted(): void
    {
        $this->increment('total_calls');
        $this->increment('completed_calls');
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Increment stats for a failed call.
     */
    public function recordFailed(): void
    {
        $this->increment('total_calls');
        $this->increment('failed_calls');
        $this->update(['last_used_at' => now()]);
    }
}
