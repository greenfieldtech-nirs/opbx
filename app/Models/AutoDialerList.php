<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Enums\ListStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[ScopedBy([OrganizationScope::class])]
class AutoDialerList extends Model
{
    use HasFactory;

    public const string STARTED_PROCESSING_MESSAGE = 'Cannot unassign list: its entries have started processing for this campaign (some destinations have been dialed or are no longer pending).';

    public const string CAMPAIGN_ACTIVE_MESSAGE = 'Cannot unassign list: its campaign is active. Pause the campaign first.';

    /**
     * The table associated with the model.
     */
    protected $table = 'auto_dialer_lists';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'campaign_id',
        'name',
        'description',
        'version_number',
        'parent_list_id',
        'is_latest_version',
        'status',
        'used_by_campaign_id',
        'used_at',
        'original_filename',
        'uploaded_by',
        'uploaded_at',
        'processed_at',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'validation_errors',
        'archived_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => ListStatus::class,
        'is_latest_version' => 'boolean',
        'validation_errors' => 'array',
        'processed_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'used_at' => 'datetime',
        'archived_at' => 'datetime',
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
     * Get the parent list (for versioned lists).
     */
    public function parentList(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_list_id');
    }

    /**
     * Previous versions of this list: the entries each re-upload replaced.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AutoDialerListVersion::class, 'list_id');
    }

    /**
     * The "(Part N)" lists ProcessLargeListJob split off this list. The name filter keeps out
     * list rows left by the retired create-new-version flow, which share parent_list_id.
     */
    public function parts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_list_id')->where('name', 'like', '% (Part %)');
    }

    /**
     * Every part of this list, and their parts in turn.
     *
     * @return Collection<int, self>
     */
    public function allParts(): Collection
    {
        return $this->parts->flatMap(fn (self $part) => [$part, ...$part->allParts()]);
    }

    /**
     * Get the campaign that used this list.
     */
    public function usedByCampaign(): BelongsTo
    {
        return $this->belongsTo(AutoDialerCampaign::class, 'used_by_campaign_id');
    }

    /**
     * Scope query to lists that are ready for assignment.
     */
    public function scopeReady($query)
    {
        return $query->where('status', ListStatus::READY);
    }

    /**
     * Scope query to lists that are processing.
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', ListStatus::PROCESSING);
    }

    /**
     * Scope query to archived lists.
     */
    public function scopeArchived($query)
    {
        return $query->where('status', ListStatus::ARCHIVED);
    }

    /**
     * Scope query to latest versions only.
     */
    public function scopeLatestVersion($query)
    {
        return $query->where('is_latest_version', true);
    }

    /**
     * Scope query to assignable lists (ready status).
     */
    public function scopeAssignable($query)
    {
        return $query->where('status', ListStatus::READY);
    }

    /**
     * Check if the list can be archived.
     */
    public function canBeArchived(): bool
    {
        if (! $this->status instanceof ListStatus) {
            return false;
        }

        return $this->status->canArchive();
    }

    /**
     * Check if the list is ready for assignment to a campaign.
     */
    public function isReady(): bool
    {
        if (! $this->status instanceof ListStatus) {
            return false;
        }

        return $this->status === ListStatus::READY;
    }

    /**
     * Check if all destinations in the list are pending with zero dial attempts.
     */
    public function allDestinationsArePendingAndFresh(): bool
    {
        // Query the entries themselves: the row counters can lag behind them.
        return $this->destinations()
            ->where(function ($query) {
                $query->where('status', '!=', 'pending')
                    ->orWhere('dial_attempts', '>', 0);
            })
            ->doesntExist();
    }

    /**
     * Check if the list can be deleted.
     */
    public function canDelete(): bool
    {
        return ! $this->isAssigned();
    }

    /**
     * Whether the list is assigned to a campaign (assigned lists cannot be deleted or archived).
     */
    public function isAssigned(): bool
    {
        return $this->campaign_id !== null;
    }

    /**
     * Why the list cannot be unassigned from its campaign right now, or null when it can:
     * refused while the campaign is active, and once any of its entries has started
     * processing (dialled, or no longer pending).
     */
    public function unassignRefusal(): ?string
    {
        if (! $this->campaign_id) {
            return 'List is not assigned to any campaign';
        }

        if ($this->campaign?->status === CampaignStatus::ACTIVE) {
            return self::CAMPAIGN_ACTIVE_MESSAGE;
        }

        return $this->allDestinationsArePendingAndFresh() ? null : self::STARTED_PROCESSING_MESSAGE;
    }

    /**
     * Check if the list can be unassigned from its campaign (see unassignRefusal()).
     */
    public function canUnassign(): bool
    {
        return $this->unassignRefusal() === null;
    }

    /**
     * Check if the list is currently locked (in use or used).
     */
    public function isLocked(): bool
    {
        if (! $this->status instanceof ListStatus) {
            return false;
        }

        return $this->status->isLocked();
    }

    /**
     * Mark the list as in use by a campaign.
     */
    public function markAsInUse(int $campaignId): void
    {
        $this->update([
            'status' => ListStatus::IN_USE,
            'campaign_id' => $campaignId,
            'used_by_campaign_id' => $campaignId,
            'used_at' => now(),
        ]);
    }

    /**
     * Mark the list as used (campaign completed).
     */
    public function markAsUsed(): void
    {
        $this->update([
            'status' => ListStatus::USED,
        ]);
    }

    /**
     * Archive the list.
     */
    public function archive(): void
    {
        $this->update([
            'status' => ListStatus::ARCHIVED,
            'archived_at' => now(),
            'is_latest_version' => false,
        ]);
    }

    /**
     * Copy this list to create a new independent list.
     */
    public function copy(string $newName): self
    {
        $copy = self::create([
            'organization_id' => $this->organization_id,
            'name' => $newName,
            'description' => $this->description,
            'version_number' => 1,
            'is_latest_version' => true,
            'status' => ListStatus::READY,
        ]);

        // Copy destinations with reset statuses
        $this->destinations->each(function (AutoDialerDestination $destination) use ($copy) {
            AutoDialerDestination::create([
                'organization_id' => $destination->organization_id,
                'list_id' => $copy->id,
                'phone_number' => $destination->phone_number,
                'name' => $destination->name,
                'batch_identifier' => $destination->batch_identifier,
                'metadata' => $destination->metadata,
                'status' => DestinationStatus::PENDING,
                'dial_attempts' => 0,
                'duration' => 0,
                'billsec' => 0,
                'total_duration' => 0,
            ]);
        });

        // Update copy statistics
        $copy->update([
            'total_rows' => $this->total_rows,
            'valid_rows' => $this->valid_rows,
            'invalid_rows' => 0,
        ]);

        return $copy;
    }
}
