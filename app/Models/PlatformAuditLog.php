<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform Audit Log Model
 *
 * Records all cross-tenant actions performed by platform managers.
 * Used for compliance, debugging, and security auditing.
 */
class PlatformAuditLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'platform_manager_user_id',
        'target_organization_id',
        'action',
        'target_entity_type',
        'target_entity_id',
        'before_state',
        'after_state',
        'reason',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
        'target_entity_id' => 'integer',
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'created_at',
        'updated_at',
    ];

    /**
     * Get the platform manager who performed the action.
     */
    public function platformManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'platform_manager_user_id');
    }

    /**
     * Get the target organization of the action.
     */
    public function targetOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'target_organization_id');
    }
}
