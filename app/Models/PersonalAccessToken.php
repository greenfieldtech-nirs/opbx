<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum personal access token with tenant-scope bypass on the tokenable owner.
 *
 * When Sanctum resolves a user from a bearer token, it loads the tokenable
 * relationship before an authenticated tenant context exists. The global
 * OrganizationScope on User would otherwise force zero results and mark the
 * request as unauthenticated.
 *
 * Also carries optional impersonation context: when a platform manager
 * impersonates an organization, the minted token stores the target organization
 * id in `impersonated_organization_id`. Requests made with such a token are
 * scoped to that organization instead of the owner's own organization.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * The attributes that are mass assignable.
     *
     * The parent Sanctum model defines its own $fillable; we extend it with the
     * impersonation column so createToken()/save() can persist it.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'impersonated_organization_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * Mirrors the parent Sanctum casts and adds the impersonation column.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'impersonated_organization_id' => 'integer',
    ];

    /**
     * Get the tokenable model owner.
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'tokenable_type', 'tokenable_id')
            ->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * The organization this token is impersonating, if any.
     */
    public function impersonatedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'impersonated_organization_id')
            ->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Whether this token is an impersonation token.
     */
    public function isImpersonation(): bool
    {
        return $this->impersonated_organization_id !== null;
    }

    /**
     * Get the impersonated organization id, if any.
     */
    public function impersonatedOrganizationId(): ?int
    {
        return $this->impersonated_organization_id !== null
            ? (int) $this->impersonated_organization_id
            : null;
    }
}
