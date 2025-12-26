<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class DidNumber extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'phone_number',
        'friendly_name',
        'routing_type',
        'routing_config',
        'status',
        'cloudonix_config',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'routing_config' => 'array',
            'cloudonix_config' => 'array',
        ];
    }

    /**
     * Get the organization that owns the DID number.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Check if the DID is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the routing target extension ID.
     */
    public function getTargetExtensionId(): ?int
    {
        if ($this->routing_type === 'extension' && isset($this->routing_config['extension_id'])) {
            return (int) $this->routing_config['extension_id'];
        }

        return null;
    }

    /**
     * Get the routing target ring group ID.
     */
    public function getTargetRingGroupId(): ?int
    {
        if ($this->routing_type === 'ring_group' && isset($this->routing_config['ring_group_id'])) {
            return (int) $this->routing_config['ring_group_id'];
        }

        return null;
    }

    /**
     * Get the routing target business hours ID.
     */
    public function getTargetBusinessHoursId(): ?int
    {
        if ($this->routing_type === 'business_hours' && isset($this->routing_config['business_hours_id'])) {
            return (int) $this->routing_config['business_hours_id'];
        }

        return null;
    }
}
