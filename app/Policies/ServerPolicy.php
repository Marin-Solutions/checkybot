<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Server;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ServerPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Server') || $authUser->can('View:Server');
    }

    public function view(AuthUser $authUser, Server $server): bool
    {
        return $this->ownsServer($authUser, $server) || $authUser->can('ViewAny:Server');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Server');
    }

    public function update(AuthUser $authUser, Server $server): bool
    {
        return $this->ownsServer($authUser, $server) || $authUser->can('UpdateAny:Server');
    }

    public function delete(AuthUser $authUser, Server $server): bool
    {
        return $this->ownsServer($authUser, $server) || $authUser->can('DeleteAny:Server');
    }

    public function restore(AuthUser $authUser, Server $server): bool
    {
        return $this->ownsServer($authUser, $server) || $authUser->can('RestoreAny:Server');
    }

    public function forceDelete(AuthUser $authUser, Server $server): bool
    {
        return $this->ownsServer($authUser, $server) || $authUser->can('ForceDeleteAny:Server');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Server');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Server');
    }

    public function replicate(AuthUser $authUser, Server $server): bool
    {
        return $this->ownsServer($authUser, $server) || $authUser->can('ReplicateAny:Server');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Server');
    }

    protected function ownsServer(AuthUser $authUser, Server $server): bool
    {
        return (int) $server->created_by === (int) $authUser->id;
    }
}
