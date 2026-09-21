<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CallQueueStatus;
use App\Enums\CallQueueStrategy;
use App\Enums\RingGroupFallbackAction;
use App\Models\AiAssistantLoadBalancer;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([OrganizationScope::class])]
class CallQueue extends Model
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
        'agent_ring_timeout',
        'max_wait_seconds',
        'wrap_up_seconds',
        'announce_position',
        'announce_position_timeout',
        'announce_position_language',
        'moh_recording_id',
        'fallback_action',
        'fallback_extension_id',
        'fallback_ring_group_id',
        'fallback_ivr_menu_id',
        'fallback_ai_assistant_id',
        'fallback_ai_load_balancer_id',
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
            'strategy' => CallQueueStrategy::class,
            'fallback_action' => RingGroupFallbackAction::class,
            'status' => CallQueueStatus::class,
            'agent_ring_timeout' => 'integer',
            'max_wait_seconds' => 'integer',
            'wrap_up_seconds' => 'integer',
            'announce_position' => 'boolean',
            'announce_position_timeout' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Agent pivot records for this queue.
     */
    public function agentRecords(): HasMany
    {
        return $this->hasMany(CallQueueAgent::class);
    }

    /**
     * Agent users for this queue (agents are users with an assigned extension).
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'call_queue_agents')->withTimestamps();
    }

    public function mohRecording(): BelongsTo
    {
        return $this->belongsTo(Recording::class, 'moh_recording_id');
    }

    public function fallbackExtension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'fallback_extension_id');
    }

    public function fallbackRingGroup(): BelongsTo
    {
        return $this->belongsTo(RingGroup::class, 'fallback_ring_group_id');
    }

    public function fallbackIvrMenu(): BelongsTo
    {
        return $this->belongsTo(IvrMenu::class, 'fallback_ivr_menu_id');
    }

    public function fallbackAiAssistant(): BelongsTo
    {
        return $this->belongsTo(AiAssistant::class, 'fallback_ai_assistant_id');
    }

    public function fallbackAiLoadBalancer(): BelongsTo
    {
        return $this->belongsTo(AiAssistantLoadBalancer::class, 'fallback_ai_load_balancer_id');
    }

    public function isActive(): bool
    {
        return $this->status === CallQueueStatus::ACTIVE;
    }

    public function scopeForOrganization(Builder $query, int|string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeWithStrategy(Builder $query, CallQueueStrategy $strategy): Builder
    {
        return $query->where('strategy', $strategy);
    }

    public function scopeWithStatus(Builder $query, CallQueueStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CallQueueStatus::ACTIVE);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }
}
