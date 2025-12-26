<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RingGroupStrategy;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class RingGroup extends Model
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
        'description',
        'strategy',
        'timeout',
        'members',
        'fallback_action',
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
            'strategy' => RingGroupStrategy::class,
            'members' => 'array',
            'fallback_action' => 'array',
            'timeout' => 'integer',
        ];
    }

    /**
     * Get the organization that owns the ring group.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Check if the ring group is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the member extension IDs.
     *
     * @return array<int>
     */
    public function getMemberExtensionIds(): array
    {
        return array_map('intval', $this->members ?? []);
    }

    /**
     * Get the member extensions.
     */
    public function getMembers(): \Illuminate\Database\Eloquent\Collection
    {
        $extensionIds = $this->getMemberExtensionIds();

        if (empty($extensionIds)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return Extension::whereIn('id', $extensionIds)
            ->where('status', 'active')
            ->get();
    }
}
