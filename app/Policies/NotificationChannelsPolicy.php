<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NotificationChannels;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class NotificationChannelsPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:NotificationChannels');
    }

    public function view(AuthUser $authUser, NotificationChannels $notificationChannels): bool
    {
        return $authUser->can('View:NotificationChannels');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:NotificationChannels');
    }

    public function update(AuthUser $authUser, NotificationChannels $notificationChannels): bool
    {
        return $authUser->can('Update:NotificationChannels');
    }

    public function delete(AuthUser $authUser, NotificationChannels $notificationChannels): bool
    {
        return $authUser->can('Delete:NotificationChannels');
    }

    public function restore(AuthUser $authUser, NotificationChannels $notificationChannels): bool
    {
        return $authUser->can('Restore:NotificationChannels');
    }

    public function forceDelete(AuthUser $authUser, NotificationChannels $notificationChannels): bool
    {
        return $authUser->can('ForceDelete:NotificationChannels');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:NotificationChannels');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:NotificationChannels');
    }

    public function replicate(AuthUser $authUser, NotificationChannels $notificationChannels): bool
    {
        return $authUser->can('Replicate:NotificationChannels');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:NotificationChannels');
    }
}
