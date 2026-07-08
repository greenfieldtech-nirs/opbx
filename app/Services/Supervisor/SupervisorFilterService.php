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
     */
    public function resourceIdentifiers(User $supervisor): array
    {
        if (! $supervisor->isSupervisor()) {
            return [];
        }

        $organizationId = $supervisor->organization_id;
        $users = $supervisor->supervisedUsers;
        $ringGroups = $supervisor->supervisedRingGroups;

        $identifiers = new Collection;

        foreach ($users as $user) {
            $identifiers->push((string) $user->id);

            $extensionNumber = Extension::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organizationId)
                ->where('user_id', $user->id)
                ->value('extension_number');

            if ($extensionNumber) {
                $identifiers->push((string) $extensionNumber);
            }
        }

        foreach ($ringGroups as $ringGroup) {
            $identifiers->push((string) $ringGroup->id);

            $extensionNumber = Extension::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organizationId)
                ->where('type', ExtensionType::RING_GROUP)
                ->where('configuration->ring_group_id', (string) $ringGroup->id)
                ->value('extension_number');

            if ($extensionNumber) {
                $identifiers->push((string) $extensionNumber);
            }
        }

        return $identifiers->filter()->unique()->values()->toArray();
    }

    public function userExtensionNumbers(User $supervisor): array
    {
        if (! $supervisor->isSupervisor()) {
            return [];
        }

        $organizationId = $supervisor->organization_id;

        return $supervisor->supervisedUsers
            ->map(fn (User $user) => Extension::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organizationId)
                ->where('user_id', $user->id)
                ->value('extension_number'))
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

        $organizationId = $supervisor->organization_id;

        return $supervisor->supervisedRingGroups
            ->map(fn ($ringGroup) => Extension::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organizationId)
                ->where('type', ExtensionType::RING_GROUP)
                ->where('configuration->ring_group_id', (string) $ringGroup->id)
                ->value('extension_number'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }
}
