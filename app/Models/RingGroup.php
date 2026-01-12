<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RingGroupFallbackAction;
use App\Enums\RingGroupStatus;
use App\Enums\RingGroupStrategy;
use App\Enums\UserStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
        'fallback_ring_group_id',
        'fallback_ivr_menu_id',
        'fallback_ai_assistant_id',
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
     * Get the fallback ring group for this ring group.
     */
    public function fallbackRingGroup(): BelongsTo
    {
        return $this->belongsTo(RingGroup::class, 'fallback_ring_group_id');
    }

    /**
     * Get the fallback IVR menu for this ring group.
     */
    public function fallbackIvrMenu(): BelongsTo
    {
        return $this->belongsTo(IvrMenu::class, 'fallback_ivr_menu_id');
    }

    /**
     * Get the fallback AI assistant for this ring group.
     */
    public function fallbackAiAssistant(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'fallback_ai_assistant_id');
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
     */
    public function getMembers(): Collection
    {
        // Use direct query to avoid Eloquent relationship issues with global scopes
        return \DB::table('ring_group_members')
            ->join('extensions', 'ring_group_members.extension_id', '=', 'extensions.id')
            ->where('ring_group_members.ring_group_id', $this->id)
            ->where('extensions.status', UserStatus::ACTIVE->value)
            ->where('extensions.organization_id', $this->organization_id)
            ->select('extensions.*')
            ->orderBy('ring_group_members.priority', 'asc')
            ->get()
            ->map(function ($row) {
                // Convert to Extension model instance
                return \App\Models\Extension::withoutGlobalScopes()->find($row->id);
            })
            ->filter(); // Remove any null extensions
    }

    /**
     * Get count of active members in this ring group.
     */
    public function getActiveMemberCount(): int
    {
        return $this->getMembers()->count();
    }

    /**
     * Scope query to ring groups in a specific organization.
     */
    public function scopeForOrganization(Builder $query, int|string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to ring groups with a specific strategy.
     */
    public function scopeWithStrategy(Builder $query, RingGroupStrategy $strategy): Builder
    {
        return $query->where('strategy', $strategy->value);
    }

    /**
     * Scope query to ring groups with a specific status.
     */
    public function scopeWithStatus(Builder $query, RingGroupStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope query to search ring groups by name or description.
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
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RingGroupStatus::ACTIVE->value);
    }
}
