<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Supervisor\StoreAssignmentsRequest;
use App\Http\Resources\SupervisorAssignmentResource;
use App\Models\RingGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SupervisorAssignmentController extends Controller
{
    public function show(User $user): SupervisorAssignmentResource
    {
        $this->authorize('viewSupervisorAssignments', $user);

        if (! $user->isSupervisor()) {
            return new SupervisorAssignmentResource($user);
        }

        return new SupervisorAssignmentResource(
            $user->load(['supervisedUsers', 'supervisedRingGroups'])
        );
    }

    public function update(StoreAssignmentsRequest $request, User $user): SupervisorAssignmentResource
    {
        $this->authorize('assignAsSupervisor', User::class);

        if (! $user->isSupervisor()) {
            abort(422, 'Target user is not a Supervisor.');
        }

        $validated = $request->validated();
        $userIds = array_unique($validated['user_ids']);
        $ringGroupIds = array_unique($validated['ring_group_ids']);

        $organizationId = $user->organization_id;

        $this->validateResourcesBelongToOrganization($userIds, $ringGroupIds, $organizationId);
        $this->validateSupervisableUsers($userIds, $user->id);

        DB::transaction(function () use ($user, $userIds, $ringGroupIds, $organizationId): void {
            $user->supervisedUsers()->detach();
            $user->supervisedRingGroups()->detach();

            foreach ($userIds as $userId) {
                $user->supervisedUsers()->attach($userId, ['organization_id' => $organizationId]);
            }

            foreach ($ringGroupIds as $ringGroupId) {
                $user->supervisedRingGroups()->attach($ringGroupId, ['organization_id' => $organizationId]);
            }
        });

        return new SupervisorAssignmentResource(
            $user->load(['supervisedUsers', 'supervisedRingGroups'])
        );
    }

    private function validateResourcesBelongToOrganization(array $userIds, array $ringGroupIds, int $organizationId): void
    {
        if (count($userIds) > 0) {
            $foreignUserCount = User::whereIn('id', $userIds)
                ->where('organization_id', '!=', $organizationId)
                ->count();

            if ($foreignUserCount > 0) {
                abort(422, 'All assigned users must belong to the same organization.');
            }
        }

        if (count($ringGroupIds) > 0) {
            $foreignRingGroupCount = RingGroup::whereIn('id', $ringGroupIds)
                ->where('organization_id', '!=', $organizationId)
                ->count();

            if ($foreignRingGroupCount > 0) {
                abort(422, 'All assigned ring groups must belong to the same organization.');
            }
        }
    }

    /**
     * Only PBX Users may be supervised. Owner, PBX Admin, Reporter, and Supervisor
     * roles are all rejected (the supervisor themselves is a Supervisor, so the
     * self-assignment case is covered here too, but is reported with a clearer message).
     */
    private function validateSupervisableUsers(array $userIds, int $supervisorId): void
    {
        if (in_array($supervisorId, $userIds, true)) {
            abort(422, 'A Supervisor cannot be assigned to themselves.');
        }

        if (count($userIds) === 0) {
            return;
        }

        $nonPbxUserCount = User::whereIn('id', $userIds)
            ->where('role', '!=', UserRole::PBX_USER)
            ->count();

        if ($nonPbxUserCount > 0) {
            abort(422, 'Only PBX Users can be assigned as supervised users.');
        }
    }
}
