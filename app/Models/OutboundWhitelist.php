<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WhitelistStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class OutboundWhitelist extends Model
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
        'destination_country',
        'destination_prefix',
        'outbound_trunk_name',
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
            'status' => WhitelistStatus::class,
        ];
    }

    /**
     * Get the organization that owns the outbound whitelist.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope query to filter by organization.
     */
    public function scopeForOrganization(Builder $query, int|string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to search outbound whitelist entries by name, destination country, prefix, or trunk name.
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('destination_country', 'like', "%{$search}%")
                ->orWhere('destination_prefix', 'like', "%{$search}%")
                ->orWhere('outbound_trunk_name', 'like', "%{$search}%");
        });
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

    /**
     * Check if this entry is active.
     */
    public function isActive(): bool
    {
        return $this->status === WhitelistStatus::ACTIVE;
    }
}
