<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BusinessHoursActionType;
use App\Enums\BusinessHoursStatus;
use App\Enums\DayOfWeek;
use App\Scopes\OrganizationScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ScopedBy([OrganizationScope::class])]
class BusinessHoursSchedule extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'business_hours_schedules';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'status',
        'open_hours_action',
        'open_hours_action_type',
        'closed_hours_action',
        'closed_hours_action_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BusinessHoursStatus::class,
            'open_hours_action' => 'json',
            'open_hours_action_type' => BusinessHoursActionType::class,
            'closed_hours_action' => 'json',
            'closed_hours_action_type' => BusinessHoursActionType::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'current_status',
    ];

    /**
     * Get the organization that owns the business hours schedule.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the schedule days for this business hours schedule.
     */
    public function scheduleDays(): HasMany
    {
        return $this->hasMany(BusinessHoursScheduleDay::class);
    }

    /**
     * Get the exceptions for this business hours schedule.
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(BusinessHoursException::class);
    }

    /**
     * Check if the business hours schedule is active.
     */
    public function isActive(): bool
    {
        return $this->status === BusinessHoursStatus::ACTIVE;
    }

    /**
     * Check if the business hours schedule is inactive.
     */
    public function isInactive(): bool
    {
        return $this->status === BusinessHoursStatus::INACTIVE;
    }

    /**
     * Get the current status of the business hours (open/closed/exception).
     *
     * @return string
     */
    public function getCurrentStatusAttribute(): string
    {
        if ($this->status === BusinessHoursStatus::INACTIVE) {
            return 'closed';
        }

        return $this->isCurrentlyOpen() ? 'open' : 'closed';
    }

    /**
     * Check if the business is currently open based on current time.
     *
     * @param Carbon|null $dateTime
     * @return bool
     */
    public function isCurrentlyOpen(?Carbon $dateTime = null): bool
    {
        $dateTime ??= Carbon::now();

        // Check if it's an exception date
        $exception = $this->getExceptionForDate($dateTime);
        if ($exception !== null) {
            return $exception->isOpen($dateTime);
        }

        // Get day of week schedule
        $dayOfWeek = DayOfWeek::fromCarbonDayOfWeek($dateTime->dayOfWeek);
        if ($dayOfWeek === null) {
            return false;
        }

        $scheduleDay = $this->scheduleDays()
            ->where('day_of_week', $dayOfWeek->value)
            ->first();

        if (!$scheduleDay || !$scheduleDay->enabled) {
            return false;
        }

        // Check if current time falls within any time range
        $currentTime = $dateTime->format('H:i:s');

        foreach ($scheduleDay->timeRanges as $timeRange) {
            if ($currentTime >= $timeRange->start_time && $currentTime < $timeRange->end_time) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the exception for a given date, if any.
     *
     * @param Carbon $dateTime
     * @return BusinessHoursException|null
     */
    public function getExceptionForDate(Carbon $dateTime): ?BusinessHoursException
    {
        $date = $dateTime->format('Y-m-d');

        return $this->exceptions()
            ->where('date', $date)
            ->first();
    }

    /**
     * Get the routing action based on current time.
     *
     * @param Carbon|null $dateTime
     * @return array|string
     */
    public function getCurrentRouting(?Carbon $dateTime = null)
    {
        $isOpen = $this->isCurrentlyOpen($dateTime);
        $action = $isOpen ? $this->open_hours_action : $this->closed_hours_action;

        // Handle both old string format and new JSON format during transition
        if (is_array($action)) {
            return $action;
        }

        // For backward compatibility, return as string if still in old format
        return $action;
    }

    /**
     * Scope query to business hours schedules in a specific organization.
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
     * Scope query to business hours schedules with a specific status.
     *
     * @param Builder $query
     * @param BusinessHoursStatus $status
     * @return Builder
     */
    public function scopeWithStatus(Builder $query, BusinessHoursStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope query to search business hours schedules by name.
     *
     * @param Builder $query
     * @param string $search
     * @return Builder
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where('name', 'like', "%{$search}%");
    }

    /**
     * Scope query to active business hours schedules only.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BusinessHoursStatus::ACTIVE->value);
    }

    /**
     * Get the open hours action type.
     *
     * @return BusinessHoursActionType
     */
    public function getOpenHoursActionType(): BusinessHoursActionType
    {
        return $this->open_hours_action_type ?? BusinessHoursActionType::EXTENSION;
    }

    /**
     * Get the closed hours action type.
     *
     * @return BusinessHoursActionType
     */
    public function getClosedHoursActionType(): BusinessHoursActionType
    {
        return $this->closed_hours_action_type ?? BusinessHoursActionType::EXTENSION;
    }

    /**
     * Get the open hours target ID.
     *
     * @return string|null
     */
    public function getOpenHoursTargetId(): ?string
    {
        $action = $this->open_hours_action;
        return is_array($action) ? ($action['target_id'] ?? null) : $action;
    }

    /**
     * Get the closed hours target ID.
     *
     * @return string|null
     */
    public function getClosedHoursTargetId(): ?string
    {
        $action = $this->closed_hours_action;
        return is_array($action) ? ($action['target_id'] ?? null) : $action;
    }

    /**
     * Get the current routing action type.
     *
     * @param Carbon|null $dateTime
     * @return BusinessHoursActionType
     */
    public function getCurrentRoutingType(?Carbon $dateTime = null): BusinessHoursActionType
    {
        return $this->isCurrentlyOpen($dateTime)
            ? $this->getOpenHoursActionType()
            : $this->getClosedHoursActionType();
    }

    /**
     * Get the current routing target ID.
     *
     * @param Carbon|null $dateTime
     * @return string|null
     */
    public function getCurrentRoutingTargetId(?Carbon $dateTime = null): ?string
    {
        return $this->isCurrentlyOpen($dateTime)
            ? $this->getOpenHoursTargetId()
            : $this->getClosedHoursTargetId();
    }
}
