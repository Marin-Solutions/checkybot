<?php

namespace Tests\Unit\Models;

use App\Models\ApiKey;
use App\Models\NotificationSetting;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Tests\TestCase;

class UserTest extends TestCase
{
    public function test_user_has_many_websites(): void
    {
        $user = User::factory()->create();
        Website::factory()->count(5)->create(['created_by' => $user->id]);

        $this->assertCount(5, $user->websites);
        $this->assertInstanceOf(Website::class, $user->websites->first());
    }

    public function test_user_has_many_servers(): void
    {
        $user = User::factory()->create();
        Server::factory()->count(3)->create(['created_by' => $user->id]);

        $this->assertCount(3, $user->servers);
        $this->assertInstanceOf(Server::class, $user->servers->first());
    }

    public function test_user_has_many_api_keys(): void
    {
        $user = User::factory()->create();
        ApiKey::factory()->count(2)->create(['user_id' => $user->id]);

        $this->assertCount(2, $user->apiKeys);
    }

    public function test_user_has_global_notification_channels(): void
    {
        $user = User::factory()->create();

        NotificationSetting::factory()->globalScope()->active()->count(2)->create([
            'user_id' => $user->id,
        ]);

        NotificationSetting::factory()->websiteScope()->create([
            'user_id' => $user->id,
        ]);

        $this->assertCount(2, $user->globalNotificationChannels);
    }

    public function test_user_can_access_filament_panel(): void
    {
        $user = User::factory()->create();

        $panel = app(\Filament\Panel::class);

        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_user_requires_email(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create(['email' => null]);
    }

    public function test_user_requires_name(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create(['name' => null]);
    }

    public function test_user_email_must_be_unique(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create(['email' => 'test@example.com']);
    }

    public function test_user_password_is_hashed(): void
    {
        $user = User::factory()->create(['password' => 'plain-password']);

        $this->assertNotEquals('plain-password', $user->password);
        $this->assertTrue(\Hash::check('plain-password', $user->password));
    }

    public function test_user_can_have_avatar_url(): void
    {
        $user = User::factory()->create(['avatar_url' => 'avatars/user.jpg']);

        $avatarUrl = $user->getFilamentAvatarUrl();

        $this->assertStringContainsString('avatars/user.jpg', $avatarUrl);
    }

    public function test_user_returns_null_avatar_url_when_not_set(): void
    {
        $user = User::factory()->create(['avatar_url' => null]);

        $this->assertNull($user->getFilamentAvatarUrl());
    }
}
