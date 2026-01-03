<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SentryBlacklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sentry_blacklist_id',
        'pattern',
        'phone_number',
        'reason',
        'created_by',
        'expires_at',
    ];

    protected $appends = [
        'phone_number',
    ];

    protected $hidden = [
        'pattern',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Map phone_number to pattern for convenience and frontend consistency.
     */
    public function setPhoneNumberAttribute($value): void
    {
        $this->attributes['pattern'] = $value;
    }

    public function getPhoneNumberAttribute(): string
    {
        return $this->pattern;
    }

    public function blacklist(): BelongsTo
    {
        return $this->belongsTo(SentryBlacklist::class, 'sentry_blacklist_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
