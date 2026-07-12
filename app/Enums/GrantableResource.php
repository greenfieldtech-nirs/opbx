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
    case RING_GROUPS = 'ring-groups';
    case AI_ASSISTANT_LOAD_BALANCERS = 'ai-assistant-load-balancers';
    case BUSINESS_HOURS = 'business-hours';
    case PHONE_NUMBERS = 'phone-numbers';
    case OUTBOUND_WHITELIST = 'outbound-whitelist';
    case INBOUND_BLACKLIST = 'inbound-blacklist';
    case CALL_DETAIL_RECORDS = 'call-detail-records';
    case RECORDINGS = 'recordings';

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * Map a matched route name (e.g. "business-hours.toggle-status") to its
     * grantable resource, or null if the route is not grantable to API keys.
     * A route belongs to a resource when its name is exactly the slug or
     * begins with "{slug}.".
     */
    public static function fromRouteName(?string $routeName): ?self
    {
        if ($routeName === null) {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($routeName === $case->value || str_starts_with($routeName, $case->value.'.')) {
                return $case;
            }
        }

        return null;
    }
}
