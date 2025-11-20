<?php

use App\Models\ApiKey;
use App\Models\NotificationSetting;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;

test('user has many websites', function () {
    $user = User::factory()->create();
    Website::factory()->count(5)->create(['created_by' => $user->id]);

    expect($user->websites)->toHaveCount(5);
    expect($user->websites->first())->toBeInstanceOf(Website::class);
});

test('user has many servers', function () {
    $user = User::factory()->create();
    Server::factory()->count(3)->create(['created_by' => $user->id]);

    expect($user->servers)->toHaveCount(3);
    expect($user->servers->first())->toBeInstanceOf(Server::class);
});

test('user has many api keys', function () {
    $user = User::factory()->create();
    ApiKey::factory()->count(2)->create(['user_id' => $user->id]);

    expect($user->apiKeys)->toHaveCount(2);
});

test('user has global notification channels', function () {
    $user = User::factory()->create();

    NotificationSetting::factory()->globalScope()->active()->count(2)->create([
        'user_id' => $user->id,
    ]);

    NotificationSetting::factory()->websiteScope()->create([
        'user_id' => $user->id,
    ]);

    expect($user->globalNotificationChannels)->toHaveCount(2);
});

test('user can access filament panel', function () {
    $user = User::factory()->create();

    $panel = app(\Filament\Panel::class);

    expect($user->canAccessPanel($panel))->toBeTrue();
});

test('user requires email', function () {
    User::factory()->create(['email' => null]);
})->throws(\Illuminate\Database\QueryException::class);

test('user requires name', function () {
    User::factory()->create(['name' => null]);
})->throws(\Illuminate\Database\QueryException::class);

test('user email must be unique', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    User::factory()->create(['email' => 'test@example.com']);
})->throws(\Illuminate\Database\QueryException::class);

test('user password is hashed', function () {
    $user = User::factory()->create(['password' => 'plain-password']);

    expect($user->password)->not->toBe('plain-password');
    expect(\Hash::check('plain-password', $user->password))->toBeTrue();
});

test('user can have avatar url', function () {
    $user = User::factory()->create(['avatar_url' => 'avatars/user.jpg']);

    $avatarUrl = $user->getFilamentAvatarUrl();

    expect($avatarUrl)->toContain('avatars/user.jpg');
});

test('user returns null avatar url when not set', function () {
    $user = User::factory()->create(['avatar_url' => null]);

    expect($user->getFilamentAvatarUrl())->toBeNull();
});
