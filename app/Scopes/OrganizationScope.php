<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Support\ImpersonationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Log;

/**
 * Organization Scope
 *
 * Global scope that restricts queries to the authenticated user's organization.
 * Supports a bypass mechanism for platform managers via the static bypass() method.
 */
class OrganizationScope implements Scope
{
    /**
     * Thread-local flag to bypass scope for current query.
     * Uses a counter to support nested bypass calls.
     */
    private static int $bypassCount = 0;

    /**
     * Execute a callback with the organization scope bypassed.
     * The scope is restored after the callback completes, even on exception.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function bypass(callable $callback): mixed
    {
        self::$bypassCount++;
        try {
            return $callback();
        } finally {
            self::$bypassCount--;
        }
    }

    /**
     * Check if scope is currently bypassed.
     */
    public static function isBypassed(): bool
    {
        return self::$bypassCount > 0;
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Skip scope application if bypassed
        if (self::isBypassed()) {
            return;
        }

        $organizationId = $this->getOrganizationId();

        if ($organizationId !== null) {
            $builder->where($model->getTable().'.organization_id', $organizationId);
        } else {
            // SECURITY: Force zero results when unauthenticated
            // This prevents unauthorized access to any organization's data
            $builder->whereRaw('1 = 0');
        }
    }

    /**
     * Get the current organization ID.
     *
     * Resolution order:
     *   1. Impersonation context — set only by SetImpersonationContext middleware
     *      from an org id stamped on the authenticated Sanctum token at mint time.
     *      This lets a platform manager act inside a target organization while
     *      keeping their own user identity.
     *   2. The authenticated user's own organization_id (normal behavior).
     */
    protected function getOrganizationId(): ?int
    {
        // Impersonation overrides the user's own organization when active.
        $impersonatedOrganizationId = ImpersonationContext::get();

        if ($impersonatedOrganizationId !== null) {
            return $impersonatedOrganizationId;
        }

        $user = auth()->user();

        if ($user && isset($user->organization_id)) {
            return (int) $user->organization_id;
        }

        // Log when no organization ID is found
        Log::debug('OrganizationScope: No authenticated user or organization_id', [
            'user' => $user ? $user->id : null,
            'has_organization_id' => $user && isset($user->organization_id),
        ]);

        return null;
    }
}
