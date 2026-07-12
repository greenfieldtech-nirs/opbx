<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyPermissionLevel;
use App\Enums\GrantableResource;
use App\Enums\UserRole;
use App\Scopes\OrganizationScope;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A long-lived, organization-scoped API key.
 *
 * The key is its own authenticatable entity (NOT a User). It carries
 * organization_id so OrganizationScope and EnsureTenantScope operate unchanged.
 * Access is governed solely by its per-resource permissions (read/write) —
 * user roles are never consulted for key-authenticated requests.
 *
 * Not scoped by OrganizationScope: the key is resolved from a bearer token
 * before a tenant context exists (mirrors PersonalAccessToken).
 */
class ApiKey extends Model implements AuthenticatableContract
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'token',
        'last_used_at',
        'created_by',
        'revoked_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withoutGlobalScope(OrganizationScope::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(ApiKeyPermission::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScope(OrganizationScope::class);
    }

    public function levelForResource(GrantableResource $resource): ?ApiKeyPermissionLevel
    {
        $permission = $this->permissions->firstWhere('resource', $resource->value);

        return $permission?->level;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * An API key is never a platform manager. Made explicit so EnsureTenantScope's
     * `$user->is_platform_manager` check does not rely on implicit null.
     */
    public function getIsPlatformManagerAttribute(): bool
    {
        return false;
    }

    // --- User role-method compatibility ---
    //
    // A granted key request has ALREADY been authorized by EnforceApiKeyScope.
    // Downstream FormRequests/controllers still call User role methods (they were
    // written for User actors). The key answers them as an OWNER-level actor so
    // pure authorization GATES pass, while the role-specific BRANCHING methods
    // (isPBXAdmin/isPBXUser/isSupervisor/isReporter) return false so key requests
    // do NOT get the supervisor-narrowed CDR view, the "own extension only"
    // restriction, or the reduced pbx-admin create rules. Real authz stays 100%
    // in the enforcer; this shim only keeps User-shaped call sites from erroring.
    // ponytail: if a future call site needs a distinct key persona, revisit — a
    // blanket owner-shim is the least code that satisfies every current gate.

    public function getRoleAttribute(): UserRole
    {
        return UserRole::OWNER;
    }

    public function hasRole(UserRole $role): bool
    {
        return $role === UserRole::OWNER;
    }

    public function isOwner(): bool
    {
        return true;
    }

    public function isPBXAdmin(): bool
    {
        return false;
    }

    public function isPBXUser(): bool
    {
        return false;
    }

    public function isReporter(): bool
    {
        return false;
    }

    public function isSupervisor(): bool
    {
        return false;
    }

    // --- Authenticatable ---

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        // API keys are resolved from a bearer token and never authenticate via a
        // password/credential provider. Reaching here means a misconfiguration
        // (e.g. an ApiKey routed through Auth::attempt()); fail loud, not silent.
        throw new \LogicException('API keys do not authenticate by password.');
    }

    public function getAuthPasswordName(): string
    {
        return 'token';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
        // no-op: API keys are not remembered
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
