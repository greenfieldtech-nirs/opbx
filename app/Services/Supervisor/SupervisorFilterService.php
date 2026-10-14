<?php

declare(strict_types=1);

namespace App\Services\Supervisor;

use App\Enums\ExtensionType;
use App\Models\ApiKey;
use App\Models\CallQueueAgent;
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
     * - the supervisor's own user ID and own extension number(s)
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

        $userIds = $users->pluck('id')->push($supervisor->id)->unique()->values()->toArray();
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
        $identifiers = $identifiers->merge(collect($userIds)->map(fn ($id) => (string) $id));
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

    /**
     * Call-data scope of a user by role: null (unrestricted) unless the user is a Supervisor, who is always
     * limited to their assigned users/groups and their own extension - never decided by a request flag.
     *
     * @return array<int, string>|null
     */
    public function scopeFor(User|ApiKey $user): ?array
    {
        return $user->isSupervisor() ? $this->resourceIdentifiers($user) : null;
    }

    /**
     * Call-record (CDR) scope of a user by role: a Supervisor sees their assigned users/groups and their own
     * extension, a PBX User only calls of or to their own extension(s); every other role (and API keys) is
     * unrestricted (null). Live Calls deliberately use scopeFor(), which leaves PBX Users unrestricted.
     * callRecordScopeColumns() names the CDR columns these identifiers are matched against.
     *
     * @return array<int, string>|null
     */
    public function callRecordScopeFor(User|ApiKey $user): ?array
    {
        if ($user->isPBXUser()) {
            $numbers = Extension::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $user->organization_id)
                ->where('type', ExtensionType::USER)
                ->where('user_id', $user->id)
                ->pluck('extension_number')
                ->filter()
                ->map(fn ($number) => (string) $number);

            // Same bare and "+"-prefixed forms as resourceIdentifiers().
            return $numbers->merge($numbers->map(fn ($number) => '+'.$number))->unique(null, true)->values()->all();
        }

        return $this->scopeFor($user);
    }

    /**
     * CDR columns matched against callRecordScopeFor(): a PBX User's calls are those of their extension
     * (extension_number) or to it; the Caller ID (`from`) does not count, since an outbound call may present a
     * DID or another number. A Supervisor additionally keeps the `from` match they had before extension_number.
     *
     * @return list<string>
     */
    public function callRecordScopeColumns(User|ApiKey $user): array
    {
        return $user->isPBXUser() ? ['extension_number', 'to'] : ['extension_number', 'from', 'to'];
    }

    /**
     * Call queues a Supervisor may read: those where an assigned user, or the supervisor, is an agent.
     *
     * @return array<int, int>
     */
    public function callQueueIds(User $supervisor): array
    {
        $userIds = $supervisor->supervisedUsers->pluck('id')->push($supervisor->id)->unique()->values()->all();

        return CallQueueAgent::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $supervisor->organization_id)
            ->whereIn('user_id', $userIds)
            ->pluck('call_queue_id')
            ->unique()
            ->values()
            ->all();
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
