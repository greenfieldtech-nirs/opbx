<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InboundBlacklistMatchType;
use App\Enums\InboundBlacklistRejectionStrategy;
use App\Enums\UserStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([OrganizationScope::class])]
class InboundBlacklist extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'match_type',
        'caller_id_pattern',
        'description',
        'did_number_id',
        'is_global',
        'rejection_strategy',
        'torment_room_prefix',
        'torment_music_timeout',
        'status',
        'expires_at',
        'blocked_count',
    ];

    protected function casts(): array
    {
        return [
            'match_type' => InboundBlacklistMatchType::class,
            'rejection_strategy' => InboundBlacklistRejectionStrategy::class,
            'status' => UserStatus::class,
            'is_global' => 'boolean',
            'expires_at' => 'datetime',
            'blocked_count' => 'integer',
            'torment_music_timeout' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function didNumber(): BelongsTo
    {
        return $this->belongsTo(DidNumber::class);
    }

    public function blockedCallLogs(): HasMany
    {
        return $this->hasMany(BlockedCallLog::class);
    }

    /**
     * Check if a caller ID matches this blacklist entry.
     */
    public function matches(string $callerId): bool
    {
        return match ($this->match_type) {
            InboundBlacklistMatchType::EXACT => $callerId === $this->caller_id_pattern,
            InboundBlacklistMatchType::PREFIX => str_starts_with($callerId, $this->caller_id_pattern),
            InboundBlacklistMatchType::WILDCARD => fnmatch($this->caller_id_pattern, $callerId),
        };
    }

    /**
     * Increment blocked count.
     */
    public function incrementBlockedCount(): void
    {
        $this->increment('blocked_count');
    }

    /**
     * Check if this entry is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Check if this entry is active and not expired.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE && ! $this->isExpired();
    }
}
