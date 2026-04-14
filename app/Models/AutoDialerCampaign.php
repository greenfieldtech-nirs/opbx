<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AmdMode;
use App\Enums\CallerIdStrategy;
use App\Enums\CampaignStatus;
use App\Enums\RoutingDestinationType;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    /**
     * Valid CAC (Concurrent Active Calls) values.
     *
     * CAC determines the maximum number of calls that can be active
     * (ringing or connected) at the same time. Valid range: 1–50.
     * CPS (Calls Per Second) controls initiation rate. Valid range: 1–30.
     */
    public const MIN_CAC = 1;

    public const MAX_CAC = 50;

    public const MIN_CPS = 1;

    public const MAX_CPS = 30;

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
        'concurrent_active_calls', // Max concurrent active calls (CAC, 1-50)
        'calls_per_second',        // Call initiation rate (CPS, 1-30)
        'days_active',
        'start_time',
        'end_time',
        'start_date',
        'end_date',
        'timezone',
        'schedule', // Full weekly schedule
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
        'pause_reason',
        'resume_at',
        'caller_id_strategy',
        'caller_id_pool_enabled',
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
        'schedule' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'record_calls' => 'boolean',
        'amd_enabled' => 'boolean',
        'amd_mode' => AmdMode::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'concurrent_active_calls' => 'integer',
        'calls_per_second' => 'integer',
        'resume_at' => 'datetime',
        'caller_id_strategy' => CallerIdStrategy::class,
        'caller_id_pool_enabled' => 'boolean',
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
     *
     * Uses the schedule object to check if current time falls within
     * any of the configured time ranges for today.
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

        // Get current day and time
        $currentDay = strtolower($now->format('l')); // monday, tuesday, etc.
        $currentTime = $now->format('H:i'); // 24-hour format: 20:43

        // Check schedule for current day
        $schedule = $this->schedule ?? [];
        if (! isset($schedule[$currentDay])) {
            return false;
        }

        $daySchedule = $schedule[$currentDay];
        if (! ($daySchedule['enabled'] ?? false)) {
            return false;
        }

        // Check if current time falls within any time range
        $timeRanges = $daySchedule['time_ranges'] ?? [];
        foreach ($timeRanges as $range) {
            $startTime = $range['start_time'] ?? '00:00';
            $endTime = $range['end_time'] ?? '00:00';

            // Handle special case where end_time is '24:00' (midnight at end of day)
            if ($endTime === '24:00') {
                $endTime = '23:59';
            }

            if ($currentTime >= $startTime && $currentTime <= $endTime) {
                return true;
            }
        }

        return false;
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

    /**
     * Calculate the API request interval in seconds.
     *
     * The dialer worker spaces out Cloudonix API requests based on the CAC
     * setting. The formula is: 60 / CAC = interval in seconds.
     *
     * Examples:
     *   CAC = 2  → Interval = 30 seconds
     *   CAC = 5  → Interval = 12 seconds
     *   CAC = 10 → Interval = 6 seconds
     *   CAC = 20 → Interval = 3 seconds
     *
     * @return float The interval in seconds between API requests
     */
    /**
     * Get the API request interval in milliseconds based on CPS.
     *
     * CPS (Calls Per Second) determines how fast calls are initiated:
     *   CPS = 1 → 1000ms between calls
     *   CPS = 2 → 500ms between calls
     *   CPS = 5 → 200ms between calls
     *   CPS = 10 → 100ms between calls
     *   CPS = 30 → ~33ms between calls
     *
     * @return float The interval in milliseconds between call initiations
     */
    public function getApiIntervalMilliseconds(): float
    {
        $cps = $this->calls_per_second ?? 1;

        if ($cps <= 0) {
            $cps = 1;
        }

        return 1000.0 / $cps;
    }

    /**
     * Check if the current CAC value is valid (1–50).
     */
    public function hasValidCac(): bool
    {
        $cac = $this->concurrent_active_calls ?? 1;

        return $cac >= self::MIN_CAC && $cac <= self::MAX_CAC;
    }

    /**
     * Check if the current CPS value is valid (1–30).
     */
    public function hasValidCps(): bool
    {
        $cps = $this->calls_per_second ?? 1;

        return $cps >= self::MIN_CPS && $cps <= self::MAX_CPS;
    }

    /**
     * Get the Caller IDs (DIDs) assigned to this campaign.
     */
    public function callerIds(): BelongsToMany
    {
        return $this->belongsToMany(
            DidNumber::class,
            'auto_dialer_campaign_caller_ids',
            'campaign_id',
            'did_number_id'
        )
            ->withPivot('weight')
            ->withTimestamps();
    }

    /**
     * Get the Caller ID statistics for this campaign.
     */
    public function callerIdStats(): HasMany
    {
        return $this->hasMany(AutoDialerCallerIdStat::class, 'campaign_id');
    }
}
