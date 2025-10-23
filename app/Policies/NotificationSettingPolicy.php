<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\NotificationSetting;
use Illuminate\Auth\Access\HandlesAuthorization;

class NotificationSettingPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:NotificationSetting');
    }

    public function view(AuthUser $authUser, NotificationSetting $notificationSetting): bool
    {
        return $authUser->can('View:NotificationSetting');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:NotificationSetting');
    }

    public function update(AuthUser $authUser, NotificationSetting $notificationSetting): bool
    {
        return $authUser->can('Update:NotificationSetting');
    }

    public function delete(AuthUser $authUser, NotificationSetting $notificationSetting): bool
    {
        return $authUser->can('Delete:NotificationSetting');
    }

    public function restore(AuthUser $authUser, NotificationSetting $notificationSetting): bool
    {
        return $authUser->can('Restore:NotificationSetting');
    }

    public function forceDelete(AuthUser $authUser, NotificationSetting $notificationSetting): bool
    {
        return $authUser->can('ForceDelete:NotificationSetting');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:NotificationSetting');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:NotificationSetting');
    }

    public function replicate(AuthUser $authUser, NotificationSetting $notificationSetting): bool
    {
        return $authUser->can('Replicate:NotificationSetting');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:NotificationSetting');
    }

}