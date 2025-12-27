<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'status',
        'timezone',
        'settings',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Organization $organization): void {
            if (empty($organization->slug)) {
                $organization->slug = Str::slug($organization->name);
            }
        });
    }

    /**
     * Get the users for the organization.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the extensions for the organization.
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class);
    }

    /**
     * Get the DID numbers for the organization.
     */
    public function didNumbers(): HasMany
    {
        return $this->hasMany(DidNumber::class);
    }

    /**
     * Get the ring groups for the organization.
     */
    public function ringGroups(): HasMany
    {
        return $this->hasMany(RingGroup::class);
    }

    /**
     * Get the business hours schedules for the organization.
     */
    public function businessHoursSchedules(): HasMany
    {
        return $this->hasMany(BusinessHoursSchedule::class);
    }

    /**
     * Get the call logs for the organization.
     */
    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    /**
     * Get the Cloudonix settings for the organization.
     */
    public function cloudonixSettings(): HasOne
    {
        return $this->hasOne(CloudonixSettings::class);
    }

    /**
     * Check if the organization is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
