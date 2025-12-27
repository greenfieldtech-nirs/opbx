<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessHoursExceptionTimeRange extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'business_hours_exception_time_ranges';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'business_hours_exception_id',
        'start_time',
        'end_time',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the exception that owns this time range.
     */
    public function exception(): BelongsTo
    {
        return $this->belongsTo(BusinessHoursException::class, 'business_hours_exception_id');
    }

    /**
     * Check if the given time falls within this time range.
     *
     * @param string $time Format: HH:mm:ss or HH:mm
     * @return bool
     */
    public function includes(string $time): bool
    {
        // Ensure consistent format for comparison
        $normalizedTime = strlen($time) === 5 ? $time . ':00' : $time;

        return $normalizedTime >= $this->start_time && $normalizedTime < $this->end_time;
    }
}
