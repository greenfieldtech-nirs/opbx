<?php

declare(strict_types=1);

namespace App\Services\Supervisor;

use App\Enums\ExtensionType;
use App\Models\Extension;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Collection;

final class SupervisorFilterService
{
    /**
     * Collect all identifiers that could represent a Supervisor's assigned resources
     * in call data (live calls or CDR). Returns a flat array of strings containing:
     * - assigned user IDs
     * - assigned users' extension numbers
     * - assigned ring group IDs
     * - assigned ring groups' extension numbers (if available)
     *
     * Also includes a "+"-prefixed variant of every identifier: session_updates
     * (used for live calls) stores caller_id/destination E.164-normalized with
     * a leading "+" even for internal extension numbers, while CDR's from/to
     * columns don't. Returning both forms lets every consumer match against
     * either data source without needing to know which format applies.
     */
    public function resourceIdentifiers(User $supervisor): array
    {
        if (! $supervisor->isSupervisor()) {
            return [];
        }

        $organizationId = $supervisor->organization_id;
        $users = $supervisor->supervisedUsers;
        $ringGroups = $supervisor->supervisedRingGroups;

        $userIds = $users->pluck('id')->toArray();
        $ringGroupIds = $ringGroups->pluck('id')->toArray();

        $userExtensionNumbers = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('type', ExtensionType::USER)
            ->whereIn('user_id', $userIds)
            ->pluck('extension_number')
            ->toArray();

        $ringGroupExtensionNumbers = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('type', ExtensionType::RING_GROUP)
            ->whereIn('configuration->ring_group_id', $ringGroupIds)
            ->pluck('extension_number')
            ->toArray();

        $identifiers = new Collection;
        $identifiers = $identifiers->merge($users->pluck('id')->map(fn ($id) => (string) $id));
        $identifiers = $identifiers->merge($userExtensionNumbers);
        $identifiers = $identifiers->merge($ringGroups->pluck('id')->map(fn ($id) => (string) $id));
        $identifiers = $identifiers->merge($ringGroupExtensionNumbers);

        $identifiers = $identifiers->filter()->unique()->values();

        // Strict uniqueness matters here: PHP's loose comparison treats
        // numeric strings like "1006" and "+1006" as equal, which would
        // silently collapse the "+"-prefixed variants back out.
        return $identifiers
            ->merge($identifiers->map(fn ($identifier) => '+'.$identifier))
            ->unique(null, true)
            ->values()
            ->toArray();
    }

    public function userExtensionNumbers(User $supervisor): array
    {
        if (! $supervisor->isSupervisor()) {
            return [];
        }

        $userIds = $supervisor->supervisedUsers->pluck('id')->toArray();

        return Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $supervisor->organization_id)
            ->where('type', ExtensionType::USER)
            ->whereIn('user_id', $userIds)
            ->pluck('extension_number')
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    public function ringGroupExtensionNumbers(User $supervisor): array
    {
        if (! $supervisor->isSupervisor()) {
            return [];
        }

        $ringGroupIds = $supervisor->supervisedRingGroups->pluck('id')->toArray();

        return Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $supervisor->organization_id)
            ->where('type', ExtensionType::RING_GROUP)
            ->whereIn('configuration->ring_group_id', $ringGroupIds)
            ->pluck('extension_number')
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }
}
