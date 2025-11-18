<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiKey;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ApiKeyPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        // Allow all authenticated users to view their own API keys
        // Resource scopes query to current user via getEloquentQuery()
        return true;
    }

    public function view(AuthUser $authUser, ApiKey $apiKey): bool
    {
        // Users can only view their own API keys
        return $apiKey->user_id === $authUser->id;
    }

    public function create(AuthUser $authUser): bool
    {
        // All authenticated users can create API keys
        return true;
    }

    public function update(AuthUser $authUser, ApiKey $apiKey): bool
    {
        // Users can only update their own API keys
        return $apiKey->user_id === $authUser->id;
    }

    public function delete(AuthUser $authUser, ApiKey $apiKey): bool
    {
        // Users can only delete their own API keys
        return $apiKey->user_id === $authUser->id;
    }

    public function restore(AuthUser $authUser, ApiKey $apiKey): bool
    {
        // Users can only restore their own API keys
        return $apiKey->user_id === $authUser->id;
    }

    public function forceDelete(AuthUser $authUser, ApiKey $apiKey): bool
    {
        // Users can only force delete their own API keys
        return $apiKey->user_id === $authUser->id;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        // Users can force delete any of their own API keys
        return true;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        // Users can restore any of their own API keys
        return true;
    }

    public function replicate(AuthUser $authUser, ApiKey $apiKey): bool
    {
        // Users can only replicate their own API keys
        return $apiKey->user_id === $authUser->id;
    }

    public function reorder(AuthUser $authUser): bool
    {
        // Users can reorder their own API keys
        return true;
    }
}
