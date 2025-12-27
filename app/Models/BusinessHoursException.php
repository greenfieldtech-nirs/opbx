<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BusinessHoursExceptionType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessHoursException extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'business_hours_exceptions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'business_hours_schedule_id',
        'date',
        'name',
        'type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => BusinessHoursExceptionType::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the business hours schedule that owns this exception.
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BusinessHoursSchedule::class, 'business_hours_schedule_id');
    }

    /**
     * Get the time ranges for this exception (only for special_hours type).
     */
    public function timeRanges(): HasMany
    {
        return $this->hasMany(BusinessHoursExceptionTimeRange::class);
    }

    /**
     * Check if this exception is of type closed.
     */
    public function isClosed(): bool
    {
        return $this->type === BusinessHoursExceptionType::CLOSED;
    }

    /**
     * Check if this exception is of type special_hours.
     */
    public function isSpecialHours(): bool
    {
        return $this->type === BusinessHoursExceptionType::SPECIAL_HOURS;
    }

    /**
     * Check if this exception is open at the given time.
     *
     * @param Carbon $dateTime
     * @return bool
     */
    public function isOpen(Carbon $dateTime): bool
    {
        // If the exception is closed all day, return false
        if ($this->isClosed()) {
            return false;
        }

        // If special hours, check if current time falls within any time range
        if ($this->isSpecialHours()) {
            $currentTime = $dateTime->format('H:i:s');

            foreach ($this->timeRanges as $timeRange) {
                if ($currentTime >= $timeRange->start_time && $currentTime < $timeRange->end_time) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
