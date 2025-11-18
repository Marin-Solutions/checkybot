<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Website;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class WebsitePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Website');
    }

    public function view(AuthUser $authUser, Website $website): bool
    {
        return $authUser->can('View:Website');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Website');
    }

    public function update(AuthUser $authUser, Website $website): bool
    {
        return $authUser->can('Update:Website');
    }

    public function delete(AuthUser $authUser, Website $website): bool
    {
        return $authUser->can('Delete:Website');
    }

    public function restore(AuthUser $authUser, Website $website): bool
    {
        return $authUser->can('Restore:Website');
    }

    public function forceDelete(AuthUser $authUser, Website $website): bool
    {
        return $authUser->can('ForceDelete:Website');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Website');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Website');
    }

    public function replicate(AuthUser $authUser, Website $website): bool
    {
        return $authUser->can('Replicate:Website');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Website');
    }
}
