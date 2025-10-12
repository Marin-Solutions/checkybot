<?php

namespace App\Policies;

use App\Models\ServerInformationHistory;
use App\Models\User;

class ServerInformationHistoryPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ServerInformationHistory $serverInformationHistory): bool
    {
        //
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ServerInformationHistory $serverInformationHistory): bool
    {
        //
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ServerInformationHistory $serverInformationHistory): bool
    {
        //
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ServerInformationHistory $serverInformationHistory): bool
    {
        //
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ServerInformationHistory $serverInformationHistory): bool
    {
        //
        return false;
    }
}
