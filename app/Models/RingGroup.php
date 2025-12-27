<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RingGroupFallbackAction;
use App\Enums\RingGroupStatus;
use App\Enums\RingGroupStrategy;
use App\Enums\UserStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([OrganizationScope::class])]
class RingGroup extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'strategy',
        'timeout',
        'ring_turns',
        'fallback_action',
        'fallback_extension_id',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy' => RingGroupStrategy::class,
            'fallback_action' => RingGroupFallbackAction::class,
            'status' => RingGroupStatus::class,
            'timeout' => 'integer',
            'ring_turns' => 'integer',
        ];
    }

    /**
     * Get the organization that owns the ring group.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the members of this ring group.
     */
    public function members(): HasMany
    {
        return $this->hasMany(RingGroupMember::class)->orderBy('priority');
    }

    /**
     * Get the fallback extension for this ring group.
     */
    public function fallbackExtension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'fallback_extension_id');
    }

    /**
     * Check if the ring group is active.
     */
    public function isActive(): bool
    {
        return $this->status === RingGroupStatus::ACTIVE;
    }

    /**
     * Check if the ring group is inactive.
     */
    public function isInactive(): bool
    {
        return $this->status === RingGroupStatus::INACTIVE;
    }

    /**
     * Get active member extensions for this ring group.
     *
     * Returns a collection of Extension models that are:
     * - Linked to this ring group via ring_group_members
     * - Have status ACTIVE
     * - Ordered by priority
     *
     * @return Collection
     */
    public function getMembers(): Collection
    {
        return $this->members()
            ->with(['extension' => function ($query) {
                $query->select('id', 'extension_number', 'user_id', 'status', 'configuration');
            }])
            ->whereHas('extension', function ($query) {
                $query->where('status', UserStatus::ACTIVE->value);
            })
            ->orderBy('priority', 'asc')
            ->get()
            ->pluck('extension');
    }

    /**
     * Get count of active members in this ring group.
     *
     * @return int
     */
    public function getActiveMemberCount(): int
    {
        return $this->getMembers()->count();
    }

    /**
     * Scope query to ring groups in a specific organization.
     *
     * @param Builder $query
     * @param int|string $organizationId
     * @return Builder
     */
    public function scopeForOrganization(Builder $query, int|string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to ring groups with a specific strategy.
     *
     * @param Builder $query
     * @param RingGroupStrategy $strategy
     * @return Builder
     */
    public function scopeWithStrategy(Builder $query, RingGroupStrategy $strategy): Builder
    {
        return $query->where('strategy', $strategy->value);
    }

    /**
     * Scope query to ring groups with a specific status.
     *
     * @param Builder $query
     * @param RingGroupStatus $status
     * @return Builder
     */
    public function scopeWithStatus(Builder $query, RingGroupStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope query to search ring groups by name or description.
     *
     * @param Builder $query
     * @param string $search
     * @return Builder
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    /**
     * Scope query to active ring groups only.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RingGroupStatus::ACTIVE->value);
    }
}
