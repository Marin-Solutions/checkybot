<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PloiAccounts;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PloiAccountsPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PloiAccounts');
    }

    public function view(AuthUser $authUser, PloiAccounts $ploiAccounts): bool
    {
        return $this->ownsPloiAccount($authUser, $ploiAccounts)
            && $authUser->can('View:PloiAccounts');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PloiAccounts');
    }

    public function update(AuthUser $authUser, PloiAccounts $ploiAccounts): bool
    {
        return $this->ownsPloiAccount($authUser, $ploiAccounts)
            && $authUser->can('Update:PloiAccounts');
    }

    public function delete(AuthUser $authUser, PloiAccounts $ploiAccounts): bool
    {
        return $this->ownsPloiAccount($authUser, $ploiAccounts)
            && $authUser->can('Delete:PloiAccounts');
    }

    public function restore(AuthUser $authUser, PloiAccounts $ploiAccounts): bool
    {
        return $this->ownsPloiAccount($authUser, $ploiAccounts)
            && $authUser->can('Restore:PloiAccounts');
    }

    public function forceDelete(AuthUser $authUser, PloiAccounts $ploiAccounts): bool
    {
        return $this->ownsPloiAccount($authUser, $ploiAccounts)
            && $authUser->can('ForceDelete:PloiAccounts');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PloiAccounts');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PloiAccounts');
    }

    public function replicate(AuthUser $authUser, PloiAccounts $ploiAccounts): bool
    {
        return $this->ownsPloiAccount($authUser, $ploiAccounts)
            && $authUser->can('Replicate:PloiAccounts');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PloiAccounts');
    }

    protected function ownsPloiAccount(AuthUser $authUser, PloiAccounts $ploiAccounts): bool
    {
        return (int) $ploiAccounts->created_by === (int) $authUser->id;
    }
}
