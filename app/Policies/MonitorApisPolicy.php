<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MonitorApis;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MonitorApisPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MonitorApis');
    }

    public function view(AuthUser $authUser, MonitorApis $monitorApis): bool
    {
        return $authUser->can('View:MonitorApis');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MonitorApis');
    }

    public function update(AuthUser $authUser, MonitorApis $monitorApis): bool
    {
        return $authUser->can('Update:MonitorApis');
    }

    public function delete(AuthUser $authUser, MonitorApis $monitorApis): bool
    {
        return $authUser->can('Delete:MonitorApis');
    }

    public function restore(AuthUser $authUser, MonitorApis $monitorApis): bool
    {
        return $authUser->can('Restore:MonitorApis');
    }

    public function forceDelete(AuthUser $authUser, MonitorApis $monitorApis): bool
    {
        return $authUser->can('ForceDelete:MonitorApis');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MonitorApis');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MonitorApis');
    }

    public function replicate(AuthUser $authUser, MonitorApis $monitorApis): bool
    {
        return $authUser->can('Replicate:MonitorApis');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MonitorApis');
    }
}
