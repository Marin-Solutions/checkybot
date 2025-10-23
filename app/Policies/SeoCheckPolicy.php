<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\SeoCheck;
use Illuminate\Auth\Access\HandlesAuthorization;

class SeoCheckPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SeoCheck');
    }

    public function view(AuthUser $authUser, SeoCheck $seoCheck): bool
    {
        return $authUser->can('View:SeoCheck');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SeoCheck');
    }

    public function update(AuthUser $authUser, SeoCheck $seoCheck): bool
    {
        return $authUser->can('Update:SeoCheck');
    }

    public function delete(AuthUser $authUser, SeoCheck $seoCheck): bool
    {
        return $authUser->can('Delete:SeoCheck');
    }

    public function restore(AuthUser $authUser, SeoCheck $seoCheck): bool
    {
        return $authUser->can('Restore:SeoCheck');
    }

    public function forceDelete(AuthUser $authUser, SeoCheck $seoCheck): bool
    {
        return $authUser->can('ForceDelete:SeoCheck');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SeoCheck');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SeoCheck');
    }

    public function replicate(AuthUser $authUser, SeoCheck $seoCheck): bool
    {
        return $authUser->can('Replicate:SeoCheck');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SeoCheck');
    }

}