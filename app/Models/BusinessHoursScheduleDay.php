<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DayOfWeek;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessHoursScheduleDay extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'business_hours_schedule_days';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'business_hours_schedule_id',
        'day_of_week',
        'enabled',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'enabled' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the business hours schedule that owns this day.
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BusinessHoursSchedule::class, 'business_hours_schedule_id');
    }

    /**
     * Get the time ranges for this schedule day.
     */
    public function timeRanges(): HasMany
    {
        return $this->hasMany(BusinessHoursTimeRange::class);
    }

    /**
     * Check if this day is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled === true;
    }
}
