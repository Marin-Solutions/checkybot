<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BackupRemoteStorageConfig;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class BackupRemoteStorageConfigPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:BackupRemoteStorageConfig');
    }

    public function view(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return $authUser->can('View:BackupRemoteStorageConfig') && $this->ownsStorage($authUser, $backupRemoteStorageConfig);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:BackupRemoteStorageConfig');
    }

    public function update(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return $authUser->can('Update:BackupRemoteStorageConfig') && $this->ownsStorage($authUser, $backupRemoteStorageConfig);
    }

    public function delete(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return $authUser->can('Delete:BackupRemoteStorageConfig') && $this->ownsStorage($authUser, $backupRemoteStorageConfig);
    }

    public function restore(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return $authUser->can('Restore:BackupRemoteStorageConfig') && $this->ownsStorage($authUser, $backupRemoteStorageConfig);
    }

    public function forceDelete(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return $authUser->can('ForceDelete:BackupRemoteStorageConfig') && $this->ownsStorage($authUser, $backupRemoteStorageConfig);
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
        return $authUser->can('Replicate:BackupRemoteStorageConfig') && $this->ownsStorage($authUser, $backupRemoteStorageConfig);
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:BackupRemoteStorageConfig');
    }

    private function ownsStorage(AuthUser $authUser, BackupRemoteStorageConfig $backupRemoteStorageConfig): bool
    {
        return (int) $backupRemoteStorageConfig->created_by === (int) $authUser->id;
    }
}
