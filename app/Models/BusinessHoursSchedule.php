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
/**
 * Business Hours Schedule Model
 *
 * Manages time-based routing rules for inbound calls. Defines open/closed hours
 * and specifies routing destinations for each state.
 *
 * ## Target ID Format
 *
 * Action target IDs use a prefixed format to identify destination types:
 * - `ext-{id}` - Extension (e.g., "ext-13")
 * - `rg-{id}` - Ring group (e.g., "rg-5")
 * - `conf-{id}` - Conference room (e.g., "conf-1")
 * - `ivr-{id}` - IVR menu (e.g., "ivr-1")
 *
 * Where `{id}` is the numeric database ID of the target entity.
 *
 * ## Action Configuration
 *
 * Actions are stored as JSON arrays with format:
 * ```php
 * [
 *   'action' => 'route',           // Action type
 *   'target_id' => 'ext-13',       // Target in prefixed format
 *   'target_type' => 'extension',  // Entity type
 * ]
 * ```
 *
 * @see BusinessHoursActionType for available action types
 * @see convertActionToRoutingFormat() for parsing logic
 */
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
        'timezone',
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

        if (! $scheduleDay || ! $scheduleDay->enabled) {
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
     * @return array|string
     */
    public function getCurrentRouting(?Carbon $dateTime = null)
    {
        $isOpen = $this->isCurrentlyOpen($dateTime);
        $action = $isOpen ? $this->open_hours_action : $this->closed_hours_action;
        $actionType = $isOpen ? $this->open_hours_action_type : $this->closed_hours_action_type;

        // Handle both old string format and new JSON format during transition
        if (is_array($action)) {
            return $this->convertActionToRoutingFormat($action, $actionType);
        }

        // For backward compatibility, return as string if still in old format
        return $action;
    }

    /**
     * Parse a target ID string into its components.
     *
     * Validates and extracts the target type and numeric ID from prefixed strings
     * like "ext-13", "rg-5", "conf-1", or "ivr-1".
     *
     * @param string $targetId The target ID in prefixed format (e.g., "ext-13")
     * @return array|null Returns ['type' => string, 'id' => int] or null if invalid
     *
     * @see BusinessHoursActionType for valid target type prefixes
     */
    public static function parseTargetId(string $targetId): ?array
    {
        $patterns = [
            '/^ext-(\d+)$/' => 'extension',
            '/^rg-(\d+)$/' => 'ring_group',
            '/^conf-(\d+)$/' => 'conference_room',
            '/^ivr-(\d+)$/' => 'ivr_menu',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $targetId, $matches)) {
                return [
                    'type' => $type,
                    'id' => (int) $matches[1],
                ];
            }
        }

        return null;
    }

    /**
     * Validate that a target ID is in the correct prefixed format.
     *
     * @param string $targetId The target ID to validate
     * @return bool True if valid, false otherwise
     */
    public static function isValidTargetId(string $targetId): bool
    {
        return self::parseTargetId($targetId) !== null;
    }

    /**
     * Convert business hours action to routing format expected by CallRoutingService.
     *
     * Parses target_id using the prefixed format (ext-13, rg-5, etc.) and extracts
     * the numeric ID for database lookups.
     *
     * @see parseTargetId() for the parsing logic
     */
    private function convertActionToRoutingFormat(array $action, BusinessHoursActionType $actionType): array
    {
        $targetId = $action['target_id'] ?? null;

        if (! $targetId) {
            return ['type' => 'voicemail', 'config' => []];
        }

        $config = [];

        switch ($actionType) {
            case BusinessHoursActionType::EXTENSION:
                // Parse "ext-13" to get extension ID 13
                if (preg_match('/^ext-(\d+)$/', $targetId, $matches)) {
                    $config['extension_id'] = (int) $matches[1];
                }
                break;

            case BusinessHoursActionType::RING_GROUP:
                // Parse "rg-5" to get ring group ID 5
                if (preg_match('/^rg-(\d+)$/', $targetId, $matches)) {
                    $config['ring_group_id'] = (int) $matches[1];
                }
                break;

            case BusinessHoursActionType::CONFERENCE_ROOM:
                // Parse "conf-1" to get conference room ID 1
                if (preg_match('/^conf-(\d+)$/', $targetId, $matches)) {
                    $config['conference_room_id'] = (int) $matches[1];
                }
                break;

            case BusinessHoursActionType::IVR_MENU:
                // Parse "ivr-1" to get IVR menu ID 1
                if (preg_match('/^ivr-(\d+)$/', $targetId, $matches)) {
                    $config['ivr_menu_id'] = (int) $matches[1];
                }
                break;
        }

        return [
            'type' => $actionType->value,
            'config' => $config,
        ];
    }

    /**
     * Scope query to business hours schedules in a specific organization.
     */
    public function scopeForOrganization(Builder $query, int|string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to business hours schedules with a specific status.
     */
    public function scopeWithStatus(Builder $query, BusinessHoursStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope query to search business hours schedules by name.
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where('name', 'like', "%{$search}%");
    }

    /**
     * Scope query to active business hours schedules only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BusinessHoursStatus::ACTIVE->value);
    }

    /**
     * Get the open hours action type.
     */
    public function getOpenHoursActionType(): BusinessHoursActionType
    {
        return $this->open_hours_action_type ?? BusinessHoursActionType::EXTENSION;
    }

    /**
     * Get the closed hours action type.
     */
    public function getClosedHoursActionType(): BusinessHoursActionType
    {
        return $this->closed_hours_action_type ?? BusinessHoursActionType::EXTENSION;
    }

    /**
     * Get the open hours target ID.
     */
    public function getOpenHoursTargetId(): ?string
    {
        $action = $this->open_hours_action;

        return is_array($action) ? ($action['target_id'] ?? null) : $action;
    }

    /**
     * Get the closed hours target ID.
     */
    public function getClosedHoursTargetId(): ?string
    {
        $action = $this->closed_hours_action;

        return is_array($action) ? ($action['target_id'] ?? null) : $action;
    }

    /**
     * Get the current routing action type.
     */
    public function getCurrentRoutingType(?Carbon $dateTime = null): BusinessHoursActionType
    {
        return $this->isCurrentlyOpen($dateTime)
            ? $this->getOpenHoursActionType()
            : $this->getClosedHoursActionType();
    }

    /**
     * Get the current routing target ID.
     */
    public function getCurrentRoutingTargetId(?Carbon $dateTime = null): ?string
    {
        return $this->isCurrentlyOpen($dateTime)
            ? $this->getOpenHoursTargetId()
            : $this->getClosedHoursTargetId();
    }
}
