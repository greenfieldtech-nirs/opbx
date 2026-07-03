<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum personal access token with tenant-scope bypass on the tokenable owner.
 *
 * When Sanctum resolves a user from a bearer token, it loads the tokenable
 * relationship before an authenticated tenant context exists. The global
 * OrganizationScope on User would otherwise force zero results and mark the
 * request as unauthenticated.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Get the tokenable model owner.
     */
    public function tokenable(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'tokenable_type', 'tokenable_id')
            ->withoutGlobalScope(OrganizationScope::class);
    }
}
