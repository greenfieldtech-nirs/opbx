<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([OrganizationScope::class])]
class AutoDialerList extends Model
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
        'name',
        'status',
        'original_filename',
        'processed_at',
        'total_rows',
        'valid_rows',
        'invalid_rows',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'processed_at' => 'datetime',
    ];

    /**
     * Get the campaign that owns the list.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AutoDialerCampaign::class, 'campaign_id');
    }

    /**
     * Get the destinations for this list.
     */
    public function destinations(): HasMany
    {
        return $this->hasMany(AutoDialerDestination::class, 'list_id');
    }

    /**
     * Get the organization that owns the list.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope query to ready lists.
     */
    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    /**
     * Scope query to processing lists.
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Check if the list is ready for dialing.
     */
    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
