<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoDialerCampaignCallerId extends Pivot
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'auto_dialer_campaign_caller_ids';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'campaign_id',
        'did_number_id',
        'weight',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'weight' => 'integer',
    ];

    /**
     * Get the campaign that owns this Caller ID assignment.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AutoDialerCampaign::class, 'campaign_id');
    }

    /**
     * Get the DID number for this Caller ID assignment.
     */
    public function didNumber(): BelongsTo
    {
        return $this->belongsTo(DidNumber::class, 'did_number_id');
    }
}
