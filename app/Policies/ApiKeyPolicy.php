<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ApiKey;
use Illuminate\Auth\Access\HandlesAuthorization;

class ApiKeyPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ApiKey');
    }

    public function view(AuthUser $authUser, ApiKey $apiKey): bool
    {
        return $authUser->can('View:ApiKey');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ApiKey');
    }

    public function update(AuthUser $authUser, ApiKey $apiKey): bool
    {
        return $authUser->can('Update:ApiKey');
    }

    public function delete(AuthUser $authUser, ApiKey $apiKey): bool
    {
        return $authUser->can('Delete:ApiKey');
    }

    public function restore(AuthUser $authUser, ApiKey $apiKey): bool
    {
        return $authUser->can('Restore:ApiKey');
    }

    public function forceDelete(AuthUser $authUser, ApiKey $apiKey): bool
    {
        return $authUser->can('ForceDelete:ApiKey');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ApiKey');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ApiKey');
    }

    public function replicate(AuthUser $authUser, ApiKey $apiKey): bool
    {
        return $authUser->can('Replicate:ApiKey');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ApiKey');
    }

}