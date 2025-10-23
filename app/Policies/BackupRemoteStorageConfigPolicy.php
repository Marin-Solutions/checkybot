<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\BackupRemoteStorageConfig;
use Illuminate\Auth\Access\HandlesAuthorization;

class BackupRemoteStorageConfigPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:BackupRemoteStorageConfig');
    }

    public function view(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return $authUser->can('View:BackupRemoteStorageConfig');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:BackupRemoteStorageConfig');
    }

    public function update(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return $authUser->can('Update:BackupRemoteStorageConfig');
    }

    public function delete(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return $authUser->can('Delete:BackupRemoteStorageConfig');
    }

    public function restore(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return $authUser->can('Restore:BackupRemoteStorageConfig');
    }

    public function forceDelete(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return $authUser->can('ForceDelete:BackupRemoteStorageConfig');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:BackupRemoteStorageConfig');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:BackupRemoteStorageConfig');
    }

    public function replicate(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return $authUser->can('Replicate:BackupRemoteStorageConfig');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:BackupRemoteStorageConfig');
    }

}