<?php

declare(strict_types=1);

namespace App\Auth;

use App\Scopes\OrganizationScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;

/**
 * Eloquent user provider that bypasses the OrganizationScope global scope.
 *
 * Authentication must be able to resolve users by credentials, token, or ID
 * before an authenticated tenant context exists. The OrganizationScope would
 * otherwise force zero results for any unauthenticated query, breaking login,
 * token refresh, and Sanctum token resolution.
 */
class BypassScopeEloquentUserProvider extends EloquentUserProvider
{
    /**
     * Retrieve a user by their unique identifier.
     */
    public function retrieveById($identifier): ?UserContract
    {
        return OrganizationScope::bypass(fn () => parent::retrieveById($identifier));
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     */
    public function retrieveByToken($identifier, $token): ?UserContract
    {
        return OrganizationScope::bypass(fn () => parent::retrieveByToken($identifier, $token));
    }

    /**
     * Retrieve a user by the given credentials.
     */
    public function retrieveByCredentials(array $credentials): ?UserContract
    {
        return OrganizationScope::bypass(fn () => parent::retrieveByCredentials($credentials));
    }
}
