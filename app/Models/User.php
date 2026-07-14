<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[ScopedBy([OrganizationScope::class])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Resolve route bindings for platform management routes without the tenant
     * scope, so platform managers can act on users across all organizations.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        if (request()->is('api/v1/platform/*')) {
            return $this->withoutGlobalScope(OrganizationScope::class)
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->first();
        }

        return parent::resolveRouteBinding($value, $field);
    }

    /**
     * Default field list for eager/lazy loading extension relationship.
     */
    public const DEFAULT_EXTENSION_FIELDS = 'extension:id,user_id,extension_number';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'password',
        'role',
        'status',
        'is_platform_manager',
        'phone',
        'street_address',
        'city',
        'state_province',
        'postal_code',
        'country',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'is_platform_manager' => false,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'is_platform_manager' => 'boolean',
        ];
    }

    /**
     * Get the organization that owns the user.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the social identities linked to the user.
     */
    public function socialIdentities(): HasMany
    {
        return $this->hasMany(UserSocialIdentity::class);
    }

    /**
     * Get the extension associated with the user.
     */
    public function extension(): HasOne
    {
        return $this->hasOne(Extension::class);
    }

    /**
     * Get the embedded-dialer token associated with the user.
     */
    public function embedToken(): HasOne
    {
        return $this->hasOne(UserEmbedToken::class);
    }

    /**
     * Get the users supervised by this supervisor.
     */
    public function supervisedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'supervisor_user_assignments', 'supervisor_id', 'user_id')
            ->withTimestamps()
            ->whereColumn('supervisor_user_assignments.organization_id', 'users.organization_id')
            ->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Get the supervisors supervising this user.
     */
    public function supervisingSupervisors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'supervisor_user_assignments', 'user_id', 'supervisor_id')
            ->withTimestamps()
            ->whereColumn('supervisor_user_assignments.organization_id', 'users.organization_id')
            ->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Get the ring groups supervised by this supervisor.
     */
    public function supervisedRingGroups(): BelongsToMany
    {
        return $this->belongsToMany(RingGroup::class, 'supervisor_ring_group_assignments', 'supervisor_id', 'ring_group_id')
            ->withTimestamps()
            ->whereColumn('supervisor_ring_group_assignments.organization_id', 'ring_groups.organization_id')
            ->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Check if user is an owner.
     */
    public function isOwner(): bool
    {
        return $this->role === UserRole::OWNER;
    }

    /**
     * Check if user is a PBX admin.
     */
    public function isPBXAdmin(): bool
    {
        return $this->role === UserRole::PBX_ADMIN;
    }

    /**
     * Check if user is a PBX user.
     */
    public function isPBXUser(): bool
    {
        return $this->role === UserRole::PBX_USER;
    }

    /**
     * Check if user is a reporter.
     */
    public function isReporter(): bool
    {
        return $this->role === UserRole::REPORTER;
    }

    /**
     * Check if user is a supervisor.
     */
    public function isSupervisor(): bool
    {
        return $this->role === UserRole::SUPERVISOR;
    }

    /**
     * Check if user can be assigned as a supervised user (not a supervisor).
     */
    public function isAssignableAsSupervisor(): bool
    {
        return $this->role !== UserRole::SUPERVISOR;
    }

    /**
     * Check if user is inactive.
     */
    public function isInactive(): bool
    {
        return $this->status === UserStatus::INACTIVE;
    }

    /**
     * Check if user is a platform manager.
     */
    public function isPlatformManager(): bool
    {
        return $this->is_platform_manager === true;
    }

    /**
     * Get the platform audit logs for this user (as platform manager).
     */
    public function platformAuditLogs(): HasMany
    {
        return $this->hasMany(PlatformAuditLog::class, 'platform_manager_user_id');
    }

    /**
     * Revoke all Sanctum tokens for this user.
     * Called when platform manager flag is revoked.
     */
    public function revokeAllTokens(): void
    {
        $this->tokens()->delete();
    }

    /**
     * Scope query to users in a specific organization.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeForOrganization($query, int|string $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to users with a specific role.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeWithRole($query, UserRole $role)
    {
        return $query->where('role', $role->value);
    }

    /**
     * Scope query to users with a specific status.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeWithStatus($query, UserStatus $status)
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope query to search users by name or email.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        });
    }

    /**
     * Check if the current user can manage the target user.
     * Business rules:
     * - Owner can manage all users
     * - PBX Admin can only manage PBX User and Reporter
     * - PBX User and Reporter cannot manage any users
     * - No one can manage themselves
     */
    public function canManageUser(User $targetUser): bool
    {
        // Cannot manage yourself
        if ($this->id === $targetUser->id) {
            return false;
        }

        // Different organizations cannot manage each other
        if ($this->organization_id !== $targetUser->organization_id) {
            return false;
        }

        // Owner can manage all users
        if ($this->role === UserRole::OWNER) {
            return true;
        }

        // PBX Admin can only manage PBX User and Reporter
        if ($this->role === UserRole::PBX_ADMIN) {
            return in_array($targetUser->role, [UserRole::PBX_USER, UserRole::REPORTER], true);
        }

        // PBX User and Reporter cannot manage any users
        return false;
    }

    /**
     * Check if user is active.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE;
    }

    /**
     * Check if the user account is pending invitation acceptance.
     */
    public function isPending(): bool
    {
        return $this->status === UserStatus::PENDING;
    }
}
