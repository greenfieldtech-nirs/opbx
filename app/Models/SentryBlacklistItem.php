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
        'reason',
        'created_by',
    ];

    public function blacklist(): BelongsTo
    {
        return $this->belongsTo(SentryBlacklist::class, 'sentry_blacklist_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
