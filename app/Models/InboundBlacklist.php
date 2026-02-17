<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InboundBlacklistMatchType;
use App\Enums\InboundBlacklistRejectionStrategy;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([OrganizationScope::class])]
class InboundBlacklist extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'match_type',
        'caller_id_pattern',
        'is_global',
        'rejection_strategy',
        'torment_room_prefix',
        'torment_music_timeout',
        'status',
        'blocked_count',
    ];

    protected function casts(): array
    {
        return [
            'match_type' => InboundBlacklistMatchType::class,
            'rejection_strategy' => InboundBlacklistRejectionStrategy::class,
            'status' => WhitelistStatus::class,
            'is_global' => 'boolean',
            'blocked_count' => 'integer',
            'torment_music_timeout' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Many-to-many relationship with DID numbers.
     */
    public function didNumbers(): BelongsToMany
    {
        return $this->belongsToMany(DidNumber::class, 'inbound_blacklist_did_number')
            ->withTimestamps();
    }

    /**
     * Get the first DID number for backward compatibility.
     */
    public function didNumber(): ?DidNumber
    {
        return $this->didNumbers()->first();
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
     * Toggle the status of this entry.
     */
    public function toggleStatus(): void
    {
        $this->status = $this->status === WhitelistStatus::ACTIVE
            ? WhitelistStatus::INACTIVE
            : WhitelistStatus::ACTIVE;
        $this->save();
    }

    public function isActive(): bool
    {
        return $this->status === WhitelistStatus::ACTIVE;
    }

    /**
     * Check if this entry is active.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE;
    }
}
