<?php

namespace Tests\Unit\Models;

use App\Models\ApiKey;
use App\Models\User;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    public function test_api_key_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $apiKey->user);
        $this->assertEquals($user->id, $apiKey->user->id);
    }

    public function test_api_key_requires_name(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        ApiKey::factory()->create(['name' => null]);
    }

    public function test_api_key_requires_key(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        ApiKey::factory()->create(['key' => null]);
    }

    public function test_api_key_has_unique_key(): void
    {
        $key = 'ck_'.\Str::random(40);
        ApiKey::factory()->create(['key' => $key]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ApiKey::factory()->create(['key' => $key]);
    }

    public function test_api_key_can_be_active(): void
    {
        $apiKey = ApiKey::factory()->create(['is_active' => true]);

        $this->assertTrue($apiKey->is_active);
    }

    public function test_api_key_can_be_inactive(): void
    {
        $apiKey = ApiKey::factory()->inactive()->create();

        $this->assertFalse($apiKey->is_active);
    }

    public function test_api_key_can_have_expiry_date(): void
    {
        $expiryDate = now()->addMonths(6);
        $apiKey = ApiKey::factory()->create(['expires_at' => $expiryDate]);

        $this->assertEquals($expiryDate->format('Y-m-d H:i'), $apiKey->expires_at->format('Y-m-d H:i'));
    }

    public function test_api_key_can_be_expired(): void
    {
        $apiKey = ApiKey::factory()->expired()->create();

        $this->assertTrue($apiKey->expires_at->isPast());
    }

    public function test_api_key_tracks_last_used_at(): void
    {
        $apiKey = ApiKey::factory()->recentlyUsed()->create();

        $this->assertNotNull($apiKey->last_used_at);
        $this->assertTrue($apiKey->last_used_at->isToday());
    }

    public function test_api_key_generates_with_ck_prefix(): void
    {
        $apiKey = ApiKey::factory()->create();

        $this->assertStringStartsWith('ck_', $apiKey->key);
        $this->assertEquals(43, strlen($apiKey->key)); // ck_ + 40 chars
    }
}
