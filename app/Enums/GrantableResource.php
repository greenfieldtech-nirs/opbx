<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Server-defined allowlist of resources an API key may be granted access to.
 * Single source of truth shared by the UI (grantable-resources endpoint) and
 * the EnforceApiKeyScope middleware. Value is the resource slug, which matches
 * the route-name prefix used by the corresponding apiResource in routes/api.php.
 */
enum GrantableResource: string
{
    case USERS = 'users';
    case EXTENSIONS = 'extensions';
    case CONFERENCE_ROOMS = 'conference-rooms';
    case AI_ASSISTANTS = 'ai-assistants';
    case AI_ASSISTANT_PROVIDERS = 'ai-assistant-providers';
    case RING_GROUPS = 'ring-groups';
    case CALL_QUEUES = 'call-queues';
    case QUEUE_CALLS = 'queue-calls';
    case AI_ASSISTANT_LOAD_BALANCERS = 'ai-assistant-load-balancers';
    case IVR_MENUS = 'ivr-menus';
    case BUSINESS_HOURS = 'business-hours';
    case PHONE_NUMBERS = 'phone-numbers';
    case OUTBOUND_WHITELIST = 'outbound-whitelist';
    case INBOUND_BLACKLIST = 'inbound-blacklist';
    case CALL_DETAIL_RECORDS = 'call-detail-records';
    case RECORDINGS = 'recordings';
    case CALL_TRACKING_CAMPAIGNS = 'call-tracking-campaigns';
    case CALL_TRACKING_ANALYTICS = 'call-tracking-analytics';
    case CALL_TRACKING_SESSIONS = 'call-tracking-sessions';
    case CALL_TRACKING_AD_PLATFORM_INTEGRATIONS = 'call-tracking-ad-platform-integrations';
    case SUPERVISORS = 'supervisors';
    case AUTO_DIALER_CAMPAIGNS = 'auto-dialer-campaigns';
    case DISTRIBUTION_LISTS = 'distribution-lists';
    case SESSION_UPDATES = 'session-updates';
    case CALL_NOTIFICATIONS = 'call-notifications';
    case DASHBOARD = 'dashboard';
    case TRUNKS = 'trunks';

    /**
     * Route-name prefixes that map to a resource whose slug differs from the
     * prefix (e.g. provider registry routes live under the singular
     * "ai-assistant." prefix, not "ai-assistants.").
     *
     * @var array<string, self>
     */
    private const ROUTE_PREFIX_ALIASES = [
        'ai-assistant.' => self::AI_ASSISTANT_PROVIDERS,
    ];

    /**
     * Route names that are NEVER grantable to API keys, even when they sit
     * under a grantable resource's prefix. These handle credentials or
     * authentication material and stay user-token-only.
     *
     * Matched by exact name or "{entry}." prefix.
     */
    private const EXCLUDED_ROUTE_PREFIXES = [
        'extensions.password',
        'extensions.reset-password',
        'users.password.update',
        'users.embed-token',
    ];

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * Route-name prefixes owned by each resource. Default is the slug itself;
     * aliases add their legacy/divergent prefixes. Single source of truth for
     * both fromRouteName() and route-coverage tests.
     *
     * @return array<string, array<int, string>> slug => route-name prefixes
     */
    public static function routePrefixes(): array
    {
        $map = [];
        foreach (self::cases() as $case) {
            $map[$case->value] = [$case->value];
        }
        foreach (self::ROUTE_PREFIX_ALIASES as $prefix => $resource) {
            $map[$resource->value][] = rtrim($prefix, '.');
        }

        return $map;
    }

    /**
     * Map a matched route name (e.g. "business-hours.toggle-status") to its
     * grantable resource, or null if the route is not grantable to API keys.
     * A route belongs to a resource when its name is exactly the slug or
     * begins with "{slug}.". Credential-bearing subroutes are always excluded.
     */
    public static function fromRouteName(?string $routeName): ?self
    {
        if ($routeName === null) {
            return null;
        }

        foreach (self::EXCLUDED_ROUTE_PREFIXES as $excluded) {
            if ($routeName === $excluded || str_starts_with($routeName, $excluded.'.')) {
                return null;
            }
        }

        foreach (self::routePrefixes() as $slug => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) {
                    return self::from($slug);
                }
            }
        }

        return null;
    }
}
