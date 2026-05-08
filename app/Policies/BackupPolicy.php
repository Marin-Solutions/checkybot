<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Backup;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class BackupPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Backup');
    }

    public function view(AuthUser $authUser, Backup $backup): bool
    {
        return $authUser->can('View:Backup') && $this->ownsBackup($authUser, $backup);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Backup');
    }

    public function update(AuthUser $authUser, Backup $backup): bool
    {
        return $authUser->can('Update:Backup') && $this->ownsBackup($authUser, $backup);
    }

    public function delete(AuthUser $authUser, Backup $backup): bool
    {
        return $authUser->can('Delete:Backup') && $this->ownsBackup($authUser, $backup);
    }

    public function restore(AuthUser $authUser, Backup $backup): bool
    {
        return $authUser->can('Restore:Backup') && $this->ownsBackup($authUser, $backup);
    }

    public function forceDelete(AuthUser $authUser, Backup $backup): bool
    {
        return $authUser->can('ForceDelete:Backup') && $this->ownsBackup($authUser, $backup);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Backup');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Backup');
    }

    public function replicate(AuthUser $authUser, Backup $backup): bool
    {
        return $authUser->can('Replicate:Backup') && $this->ownsBackup($authUser, $backup);
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Backup');
    }

    private function ownsBackup(AuthUser $authUser, Backup $backup): bool
    {
        return (int) $backup->created_by === (int) $authUser->id
            && (int) $backup->server?->created_by === (int) $authUser->id;
    }
}
