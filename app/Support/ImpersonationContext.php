<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Impersonation Context
 *
 * Request-local holder for the organization a platform manager is currently
 * impersonating. Set exclusively by the SetImpersonationContext middleware from
 * a value stamped on the authenticated Sanctum token at mint time, and read by
 * OrganizationScope to scope queries to the impersonated organization.
 *
 * The value is process/request-local (a single static int) and MUST be cleared
 * at the end of each request to prevent leakage across requests in long-running
 * workers (octane/queue). The middleware clears it in a finally block.
 */
final class ImpersonationContext
{
    private static ?int $organizationId = null;

    /**
     * Set the impersonated organization id for the current request.
     */
    public static function set(?int $organizationId): void
    {
        self::$organizationId = $organizationId;
    }

    /**
     * Get the impersonated organization id, if any.
     */
    public static function get(): ?int
    {
        return self::$organizationId;
    }

    /**
     * Whether an impersonation context is currently active.
     */
    public static function isActive(): bool
    {
        return self::$organizationId !== null;
    }

    /**
     * Clear the impersonation context.
     */
    public static function clear(): void
    {
        self::$organizationId = null;
    }
}
