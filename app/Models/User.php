<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[ScopedBy([OrganizationScope::class])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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
        'phone',
        'street_address',
        'city',
        'state_province',
        'postal_code',
        'country',
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
     * Get the extension associated with the user.
     */
    public function extension(): HasOne
    {
        return $this->hasOne(Extension::class);
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
     * Check if user is an admin (Owner or PBX Admin).
     *
     * @deprecated Use role permission methods instead (canManageUsers, etc.)
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, [UserRole::OWNER, UserRole::PBX_ADMIN], true);
    }

    /**
     * Scope query to users in a specific organization.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|string $organizationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForOrganization($query, int|string $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to users with a specific role.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param UserRole $role
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithRole($query, UserRole $role)
    {
        return $query->where('role', $role->value);
    }

    /**
     * Scope query to users with a specific status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param UserStatus $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithStatus($query, UserStatus $status)
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope query to search users by name or email.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $search
     * @return \Illuminate\Database\Eloquent\Builder
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
     *
     * @param User $targetUser
     * @return bool
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
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE;
    }

    /**
     * Check if user is inactive.
     *
     * @return bool
     */
    public function isInactive(): bool
    {
        return $this->status === UserStatus::INACTIVE;
    }
}
