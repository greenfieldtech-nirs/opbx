<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlbsStatus;
use App\Enums\AlbsStrategy;
use App\Enums\RingGroupFallbackAction;
use App\Enums\UserStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

#[ScopedBy([OrganizationScope::class])]
class AiAssistantLoadBalancer extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Default field list for lazy loading minimal relationships in CRUD operations.
     */
    public const DEFAULT_RELATIONSHIP_FIELDS = [
        'members.aiAssistant:id,name,status',
        'fallbackExtension:id,extension_number',
        'fallbackRingGroup:id,name',
        'fallbackIvrMenu:id,name',
        'fallbackAiAssistant:id,name',
    ];

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
        'status',
        'fallback_action',
        'fallback_extension_id',
        'fallback_ring_group_id',
        'fallback_ivr_menu_id',
        'fallback_ai_assistant_id',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy' => AlbsStrategy::class,
            'fallback_action' => RingGroupFallbackAction::class,
            'status' => AlbsStatus::class,
        ];
    }

    /**
     * Get the organization that owns the load balancer.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the members of this load balancer.
     */
    public function members(): HasMany
    {
        return $this->hasMany(AiAssistantLoadBalancerMember::class, 'load_balancer_id')
            ->orderBy('position');
    }

    /**
     * Get the fallback extension for this load balancer.
     */
    public function fallbackExtension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'fallback_extension_id');
    }

    /**
     * Get the fallback ring group for this load balancer.
     */
    public function fallbackRingGroup(): BelongsTo
    {
        return $this->belongsTo(RingGroup::class, 'fallback_ring_group_id');
    }

    /**
     * Get the fallback IVR menu for this load balancer.
     */
    public function fallbackIvrMenu(): BelongsTo
    {
        return $this->belongsTo(IvrMenu::class, 'fallback_ivr_menu_id');
    }

    /**
     * Get the fallback AI assistant for this load balancer.
     */
    public function fallbackAiAssistant(): BelongsTo
    {
        return $this->belongsTo(AiAssistant::class, 'fallback_ai_assistant_id');
    }

    /**
     * Get the user who created this load balancer.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this load balancer.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Check if the load balancer is active.
     */
    public function isActive(): bool
    {
        return $this->status === AlbsStatus::ACTIVE;
    }

    /**
     * Check if the load balancer is inactive.
     */
    public function isInactive(): bool
    {
        return $this->status === AlbsStatus::INACTIVE;
    }

    /**
     * Get active member AI assistants for this load balancer.
     *
     * Returns a collection of members that are:
     * - Linked to this load balancer
     * - Have status ACTIVE
     * - Have an active AI assistant
     * - Ordered by position
     */
    public function getActiveMembers(): Collection
    {
        return $this->members()
            ->where('status', 'active')
            ->whereHas('aiAssistant', fn ($q) => $q->where('status', UserStatus::ACTIVE->value))
            ->orderBy('position')
            ->get();
    }

    /**
     * Get count of active members in this load balancer.
     */
    public function getActiveMemberCount(): int
    {
        return $this->getActiveMembers()->count();
    }

    /**
     * Scope query to load balancers in a specific organization.
     */
    public function scopeForOrganization(Builder $query, int|string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to load balancers with a specific strategy.
     */
    public function scopeWithStrategy(Builder $query, AlbsStrategy $strategy): Builder
    {
        return $query->where('strategy', $strategy->value);
    }

    /**
     * Scope query to load balancers with a specific status.
     */
    public function scopeWithStatus(Builder $query, AlbsStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope query to search load balancers by name or description.
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    /**
     * Scope query to active load balancers only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AlbsStatus::ACTIVE->value);
    }
}
