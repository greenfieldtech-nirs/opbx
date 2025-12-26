<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class BusinessHours extends Model
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
        'timezone',
        'schedules',
        'holidays',
        'open_hours_routing',
        'closed_hours_routing',
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
            'schedules' => 'array',
            'holidays' => 'array',
            'open_hours_routing' => 'array',
            'closed_hours_routing' => 'array',
        ];
    }

    /**
     * Get the organization that owns the business hours.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Check if the business hours is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the business is currently open.
     */
    public function isOpen(?Carbon $dateTime = null): bool
    {
        $dateTime ??= Carbon::now($this->timezone);

        // Check if it's a holiday
        if ($this->isHoliday($dateTime)) {
            return false;
        }

        // Get day of week schedule
        $dayOfWeek = strtolower($dateTime->format('l'));
        $schedule = $this->schedules[$dayOfWeek] ?? null;

        if (!$schedule || !isset($schedule['enabled']) || !$schedule['enabled']) {
            return false;
        }

        // Check time ranges
        $currentTime = $dateTime->format('H:i');

        foreach ($schedule['times'] ?? [] as $timeRange) {
            if ($currentTime >= $timeRange['start'] && $currentTime < $timeRange['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the given date is a holiday.
     */
    public function isHoliday(Carbon $dateTime): bool
    {
        $date = $dateTime->format('Y-m-d');

        return in_array($date, $this->holidays ?? [], true);
    }

    /**
     * Get the routing configuration based on current time.
     */
    public function getCurrentRouting(?Carbon $dateTime = null): array
    {
        return $this->isOpen($dateTime)
            ? $this->open_hours_routing
            : $this->closed_hours_routing;
    }
}
