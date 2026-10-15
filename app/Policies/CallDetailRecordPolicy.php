<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CallDetailRecord;
use App\Models\User;
use App\Services\Supervisor\SupervisorFilterService;

/**
 * Call Detail Record Policy
 *
 * Handles authorization for CDR operations (read-only access).
 */
class CallDetailRecordPolicy
{
    /**
     * Determine if the user can view any call detail records.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view CDRs in their organization
        return true;
    }

    /**
     * Determine if the user can view the call detail record.
     */
    public function view(User $user, CallDetailRecord $callDetailRecord): bool
    {
        // User can view if the CDR belongs to their organization
        if ($user->organization_id !== $callDetailRecord->organization_id) {
            return false;
        }

        // Supervisors only view CDRs of their assigned resources, PBX Users only CDRs of or to their own extension(s)
        $scope = app(SupervisorFilterService::class);
        $identifiers = $scope->callRecordScopeFor($user);

        if ($identifiers === null) {
            return true;
        }

        foreach ($scope->callRecordScopeColumns($user) as $column) {
            if (in_array((string) $callDetailRecord->{$column}, $identifiers, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Playing a call's recording: Owner, PBX Admin, Reporter (D13) and Supervisors, always on top of view
     * (a Supervisor only plays CDRs of their assigned users/groups).
     */
    public function playRecording(User $user, CallDetailRecord $callDetailRecord): bool
    {
        return ($user->isSupervisor() || app(RecordingPolicy::class)->viewAny($user))
            && $this->view($user, $callDetailRecord);
    }

    /**
     * Determine if the user can create call detail records.
     */
    public function create(User $user): bool
    {
        // CDRs are system-generated, not user-creatable
        return false;
    }

    /**
     * Determine if the user can update the call detail record.
     */
    public function update(User $user, CallDetailRecord $callDetailRecord): bool
    {
        // CDRs are immutable
        return false;
    }

    /**
     * Determine if the user can delete the call detail record.
     */
    public function delete(User $user, CallDetailRecord $callDetailRecord): bool
    {
        // CDRs should not be deleted (audit trail)
        return false;
    }
}
