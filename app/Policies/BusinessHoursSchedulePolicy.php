<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BusinessHoursSchedule;
use App\Models\User;

/**
 * Business Hours Schedule authorization policy.
 *
 * Defines authorization rules for business hours schedule management operations
 * based on the role-based access control system.
 *
 * Authorization rules:
 * - Owner: Full access to all business hours schedules
 * - PBX Admin: Full access to all business hours schedules
 * - PBX User (Agent): Read-only access to business hours schedules
 * - Reporter: Read-only access to business hours schedules
 */
class BusinessHoursSchedulePolicy
{
    /**
     * Determine if the user can view the business hours schedules list.
     *
     * All authenticated users can view business hours schedules within their organization.
     *
     * @param User $user The authenticated user
     * @return bool True if authorized to view business hours schedules list
     */
    public function viewAny(User $user): bool
    {
        // All roles can view business hours schedules
        return true;
    }

    /**
     * Determine if the user can view a specific business hours schedule.
     *
     * Users can view any business hours schedule within their organization.
     *
     * @param User $user The authenticated user
     * @param BusinessHoursSchedule $schedule The business hours schedule being viewed
     * @return bool True if authorized to view the business hours schedule
     */
    public function view(User $user, BusinessHoursSchedule $schedule): bool
    {
        // All roles can view business hours schedules within their organization
        return $user->organization_id === $schedule->organization_id;
    }

    /**
     * Determine if the user can create business hours schedules.
     *
     * Only Owner and PBX Admin can create business hours schedules.
     *
     * @param User $user The authenticated user
     * @return bool True if authorized to create business hours schedules
     */
    public function create(User $user): bool
    {
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can update a business hours schedule.
     *
     * Only Owner and PBX Admin can update business hours schedules.
     *
     * @param User $user The authenticated user
     * @param BusinessHoursSchedule $schedule The business hours schedule being updated
     * @return bool True if authorized to update the business hours schedule
     */
    public function update(User $user, BusinessHoursSchedule $schedule): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $schedule->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can update business hours schedules
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can delete a business hours schedule.
     *
     * Only Owner and PBX Admin can delete business hours schedules.
     *
     * @param User $user The authenticated user
     * @param BusinessHoursSchedule $schedule The business hours schedule being deleted
     * @return bool True if authorized to delete the business hours schedule
     */
    public function delete(User $user, BusinessHoursSchedule $schedule): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $schedule->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can delete business hours schedules
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can duplicate a business hours schedule.
     *
     * Only Owner and PBX Admin can duplicate business hours schedules.
     *
     * @param User $user The authenticated user
     * @param BusinessHoursSchedule $schedule The business hours schedule being duplicated
     * @return bool True if authorized to duplicate the business hours schedule
     */
    public function duplicate(User $user, BusinessHoursSchedule $schedule): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $schedule->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can duplicate business hours schedules
        return $user->isOwner() || $user->isPBXAdmin();
    }
}
