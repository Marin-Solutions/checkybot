<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProxyPoolIntegration;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProxyPoolIntegrationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProxyPoolIntegration');
    }

    public function view(AuthUser $authUser, ProxyPoolIntegration $proxyPoolIntegration): bool
    {
        return $this->ownsIntegration($authUser, $proxyPoolIntegration)
            && $authUser->can('View:ProxyPoolIntegration');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProxyPoolIntegration');
    }

    public function update(AuthUser $authUser, ProxyPoolIntegration $proxyPoolIntegration): bool
    {
        return $this->ownsIntegration($authUser, $proxyPoolIntegration)
            && $authUser->can('Update:ProxyPoolIntegration');
    }

    public function delete(AuthUser $authUser, ProxyPoolIntegration $proxyPoolIntegration): bool
    {
        return $this->ownsIntegration($authUser, $proxyPoolIntegration)
            && $authUser->can('Delete:ProxyPoolIntegration');
    }

    public function restore(AuthUser $authUser, ProxyPoolIntegration $proxyPoolIntegration): bool
    {
        return $this->ownsIntegration($authUser, $proxyPoolIntegration)
            && $authUser->can('Restore:ProxyPoolIntegration');
    }

    public function forceDelete(AuthUser $authUser, ProxyPoolIntegration $proxyPoolIntegration): bool
    {
        return $this->ownsIntegration($authUser, $proxyPoolIntegration)
            && $authUser->can('ForceDelete:ProxyPoolIntegration');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProxyPoolIntegration');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProxyPoolIntegration');
    }

    public function replicate(AuthUser $authUser, ProxyPoolIntegration $proxyPoolIntegration): bool
    {
        return $this->ownsIntegration($authUser, $proxyPoolIntegration)
            && $authUser->can('Replicate:ProxyPoolIntegration');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProxyPoolIntegration');
    }

    protected function ownsIntegration(AuthUser $authUser, ProxyPoolIntegration $proxyPoolIntegration): bool
    {
        return (int) $proxyPoolIntegration->created_by === (int) $authUser->id;
    }
}
