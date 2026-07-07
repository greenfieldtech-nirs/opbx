<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DestinationStatus;
use App\Enums\ListStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ScopedBy([OrganizationScope::class])]
class AutoDialerList extends Model
{
    use HasFactory;

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
     * Get all versions of this list.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_list_id');
    }

    /**
     * Get the latest version of this list lineage.
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(self::class, 'parent_list_id')
            ->where('is_latest_version', true)
            ->orderByDesc('version_number');
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
        if ($this->statistics['total_rows'] ?? $this->total_rows === 0) {
            return true;
        }

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
        // Not assigned to any campaign
        if (! $this->campaign_id) {
            return true;
        }

        // Assigned, but all entries are pending with zero dial attempts
        return $this->allDestinationsArePendingAndFresh();
    }

    /**
     * Check if the list can be unassigned from its campaign.
     */
    public function canUnassign(): bool
    {
        if (! $this->campaign_id) {
            return false;
        }

        return $this->allDestinationsArePendingAndFresh();
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
     * Get the version history for this list.
     *
     * @return Collection<int, self>
     */
    public function getVersionHistory(): Collection
    {
        // If this is the root list (no parent), get all children
        if ($this->parent_list_id === null) {
            return self::where('parent_list_id', $this->id)
                ->orWhere('id', $this->id)
                ->orderBy('version_number')
                ->get();
        }

        // If this is a child, find the root first
        $root = $this->parentList;
        while ($root->parent_list_id !== null) {
            $root = $root->parentList;
        }

        return self::where('parent_list_id', $root->id)
            ->orWhere('id', $root->id)
            ->orderBy('version_number')
            ->get();
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
     * Create a new version of this list.
     */
    public function createNewVersion(string $name): self
    {
        // Archive current version if it's the latest
        if ($this->is_latest_version) {
            $this->update(['is_latest_version' => false]);
        }

        // Determine version number
        $maxVersion = self::where('parent_list_id', $this->id)
            ->orWhere('id', $this->id)
            ->max('version_number') ?? 0;

        return self::create([
            'organization_id' => $this->organization_id,
            'name' => $name,
            'version_number' => $maxVersion + 1,
            'parent_list_id' => $this->parent_list_id ?? $this->id,
            'is_latest_version' => true,
            'status' => ListStatus::DRAFT,
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
