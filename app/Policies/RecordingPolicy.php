<?php

namespace App\Policies;

use App\Models\Recording;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RecordingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['owner', 'admin']) &&
               $user->organization_id !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Recording $recording): bool
    {
        return $user->hasRole(['owner', 'admin']) &&
               $user->organization_id === $recording->organization_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(['owner', 'admin']) &&
               $user->organization_id !== null;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Recording $recording): bool
    {
        return $user->hasRole(['owner', 'admin']) &&
               $user->organization_id === $recording->organization_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Recording $recording): bool
    {
        return $user->hasRole(['owner', 'admin']) &&
               $user->organization_id === $recording->organization_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Recording $recording): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Recording $recording): bool
    {
        return false;
    }
}
