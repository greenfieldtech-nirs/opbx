<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;

/**
 * Token Abilities
 *
 * Single source of truth for Sanctum token abilities by role, plus the extra
 * abilities granted to platform managers. Previously this map was duplicated
 * across AuthController, Auth0Controller and RegisterController; centralizing it
 * here prevents drift (and is reused by the impersonation controller).
 *
 * Note: abilities are stored on tokens but not currently enforced via
 * tokenCan()/ability middleware. They remain advisory + forward-looking.
 */
final class TokenAbilities
{
    /**
     * Role => abilities map.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_ABILITIES = [
        // Owner: Full access to all resources
        'owner' => [
            'extension:*',
            'user:*',
            'ring-group:*',
            'did-number:*',
            'recording:*',
            'settings:*',
            'business-hours:*',
            'conference:*',
            'ivr:*',
            'voice-agent:*',
            'call-log:*',
            'outbound-whitelist:*',
            'recording-download:*',
        ],
        // PBX Admin: Full access except user management and sensitive settings
        'pbx_admin' => [
            'extension:*',
            'user:read',
            'user:update',
            'ring-group:*',
            'did-number:*',
            'recording:read',
            'business-hours:*',
            'conference:*',
            'ivr:*',
            'call-log:*',
        ],
        // PBX User: Read access to most, can update own extension
        'pbx_user' => [
            'extension:read',
            'extension:update:own',
            'user:read',
            'ring-group:read',
            'did-number:read',
            'recording:read',
            'call-log:read',
        ],
        // Reporter: Read-only access
        'reporter' => [
            'extension:read',
            'user:read',
            'ring-group:read',
            'did-number:read',
            'recording:read',
            'call-log:read',
            'business-hours:read',
        ],
        // Supervisor: Read-only access plus supervisor features
        'supervisor' => [
            'extension:read',
            'user:read',
            'ring-group:read',
            'did-number:read',
            'recording:read',
            'call-log:read',
            'business-hours:read',
            'supervisor:view',
            'supervisor:assignments',
        ],
    ];

    /**
     * Extra abilities granted to platform managers.
     *
     * @var list<string>
     */
    private const PLATFORM_ABILITIES = [
        'platform:read',
        'platform:write',
        'platform:manage-users',
        'platform:manage-organizations',
        'platform:audit-logs',
    ];

    /**
     * Get the abilities for a role, optionally merging platform abilities.
     *
     * @return list<string>
     */
    public static function forRole(UserRole|string $role, bool $isPlatformManager = false): array
    {
        $roleValue = $role instanceof UserRole ? $role->value : $role;

        $abilities = self::ROLE_ABILITIES[$roleValue] ?? self::ROLE_ABILITIES['reporter'];

        if ($isPlatformManager) {
            $abilities = array_merge($abilities, self::PLATFORM_ABILITIES);
        }

        return $abilities;
    }

    /**
     * Get the full owner ability set (used for impersonation, which always acts
     * as a full org owner within the target organization).
     *
     * @return list<string>
     */
    public static function owner(): array
    {
        return self::ROLE_ABILITIES['owner'];
    }

    /**
     * Get the platform-only abilities.
     *
     * @return list<string>
     */
    public static function platform(): array
    {
        return self::PLATFORM_ABILITIES;
    }
}
