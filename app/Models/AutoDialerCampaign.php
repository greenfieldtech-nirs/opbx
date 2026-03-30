<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AmdMode;
use App\Enums\CampaignStatus;
use App\Enums\RoutingDestinationType;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ScopedBy([OrganizationScope::class])]
class AutoDialerCampaign extends Model
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
        'status',
        'auto_start',
        'routing_destination_type',
        'routing_destination_id',
        'dial_timeout',
        'destination_connect',
        'caller_id',
        'max_dial_attempts',
        'calls_per_second',
        'days_active',
        'start_time',
        'end_time',
        'start_date',
        'end_date',
        'timezone',
        'schedule', // New field for full weekly schedule
        'time_limit',
        'record_calls',
        'amd_enabled',
        'amd_mode',
        'amd_timeout',
        'amd_speech_threshold',
        'amd_speech_end_threshold',
        'amd_silence_timeout',
        'total_destinations',
        'completed_calls',
        'failed_calls',
        'pending_calls',
        'concurrent_active_calls',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => CampaignStatus::class,
        'routing_destination_type' => RoutingDestinationType::class,
        'destination_connect' => 'string',
        'auto_start' => 'boolean',
        'days_active' => 'array',
        'schedule' => 'array', // New field for full weekly schedule
        'start_date' => 'date',
        'end_date' => 'date',
        'record_calls' => 'boolean',
        'amd_enabled' => 'boolean',
        'amd_mode' => AmdMode::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'concurrent_active_calls' => 'integer',
    ];

    /**
     * Scope query to a specific organization.
     */
    public function scopeForOrganization($query, int|string $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Get the organization that owns the campaign.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the list for this campaign.
     */
    public function list(): HasOne
    {
        return $this->hasOne(AutoDialerList::class, 'campaign_id');
    }

    /**
     * Get the destinations for this campaign through the list.
     */
    public function destinations(): HasManyThrough
    {
        return $this->hasManyThrough(AutoDialerDestination::class, AutoDialerList::class, 'campaign_id', 'list_id', 'id', 'id');
    }

    /**
     * Get the call sessions for this campaign.
     */
    public function callSessions(): HasMany
    {
        return $this->hasMany(AutoDialerCallSession::class, 'campaign_id');
    }

    /**
     * Get the AI Assistant if routing to AI Assistant.
     */
    public function aiAssistant(): BelongsTo
    {
        return $this->belongsTo(AiAssistant::class, 'routing_destination_id');
    }

    /**
     * Get the AI Load Balancer if routing to AI Load Balancer.
     */
    public function aiLoadBalancer(): BelongsTo
    {
        return $this->belongsTo(AiAssistantLoadBalancer::class, 'routing_destination_id');
    }

    /**
     * Scope query to active campaigns.
     */
    public function scopeActive($query)
    {
        return $query->where('status', CampaignStatus::ACTIVE);
    }

    /**
     * Scope query to draft campaigns.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', CampaignStatus::DRAFT);
    }

    /**
     * Scope query to paused campaigns.
     */
    public function scopePaused($query)
    {
        return $query->where('status', CampaignStatus::PAUSED);
    }

    /**
     * Scope query to runnable campaigns (active and within schedule).
     */
    public function scopeRunnable($query)
    {
        return $query->where('status', CampaignStatus::ACTIVE)
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now());
    }

    /**
     * Check if the campaign can be started.
     */
    public function canStart(): bool
    {
        return $this->status->canStart();
    }

    /**
     * Check if the campaign can be paused.
     */
    public function canPause(): bool
    {
        return $this->status->canPause();
    }

    /**
     * Check if the campaign can accept a list assignment.
     */
    public function canAcceptList(): bool
    {
        return $this->status->canAcceptList();
    }

    /**
     * Check if the campaign is currently runnable.
     */
    public function isRunnable(): bool
    {
        if (! $this->status->isRunnable()) {
            return false;
        }

        // Check date range
        $now = now();
        if ($now->lt($this->start_date) || $now->gt($this->end_date)) {
            return false;
        }

        // Check day of week
        $currentDay = strtolower($now->format('l'));
        if (! in_array($currentDay, $this->days_active ?? [], true)) {
            return false;
        }

        // Check time range
        $currentHour = (int) $now->format('G');
        if ($currentHour < $this->start_time || $currentHour >= $this->end_time) {
            return false;
        }

        return true;
    }

    /**
     * Get progress percentage.
     */
    public function getProgressPercentage(): int
    {
        if ($this->total_destinations === 0) {
            return 0;
        }

        $processed = $this->completed_calls + $this->failed_calls;

        return (int) round(($processed / $this->total_destinations) * 100);
    }

    /**
     * Check if campaign has a list uploaded.
     */
    public function hasList(): bool
    {
        return $this->list !== null && $this->list->status === 'ready';
    }

    /**
     * Get pending destinations count.
     */
    public function getPendingCount(): int
    {
        return $this->destinations()
            ->where('status', 'pending')
            ->count();
    }

    /**
     * Get the routing destination label.
     */
    public function getRoutingDestinationLabel(): string
    {
        return $this->routing_destination_type->label();
    }
}
