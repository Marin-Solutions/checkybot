<?php

use App\Models\ApiKey;
use App\Models\User;

test('api key belongs to user', function () {
    $user = User::factory()->create();
    $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);

    expect($apiKey->user)->toBeInstanceOf(User::class);
    expect($apiKey->user->id)->toBe($user->id);
});

test('api key requires name', function () {
    ApiKey::factory()->create(['name' => null]);
})->throws(\Illuminate\Database\QueryException::class);

test('api key requires key', function () {
    ApiKey::factory()->create(['key' => null]);
})->throws(\Illuminate\Database\QueryException::class);

test('api key has unique key', function () {
    $key = 'ck_'.\Str::random(40);
    ApiKey::factory()->create(['key' => $key]);

    ApiKey::factory()->create(['key' => $key]);
})->throws(\Illuminate\Database\QueryException::class);

test('api key can be active', function () {
    $apiKey = ApiKey::factory()->create(['is_active' => true]);

    expect($apiKey->is_active)->toBeTrue();
});

test('api key can be inactive', function () {
    $apiKey = ApiKey::factory()->inactive()->create();

    expect($apiKey->is_active)->toBeFalse();
});

test('api key can have expiry date', function () {
    $expiryDate = now()->addMonths(6);
    $apiKey = ApiKey::factory()->create(['expires_at' => $expiryDate]);

    expect($apiKey->expires_at->format('Y-m-d H:i'))->toBe($expiryDate->format('Y-m-d H:i'));
});

test('api key can be expired', function () {
    $apiKey = ApiKey::factory()->expired()->create();

    expect($apiKey->expires_at->isPast())->toBeTrue();
});

test('api key tracks last used at', function () {
    $apiKey = ApiKey::factory()->recentlyUsed()->create();

    expect($apiKey->last_used_at)->not->toBeNull();
    expect($apiKey->last_used_at->isToday())->toBeTrue();
});

test('api key generates with ck prefix', function () {
    $apiKey = ApiKey::factory()->create();

    expect($apiKey->key)->toStartWith('ck_');
    expect(strlen($apiKey->key))->toBe(43); // ck_ + 40 chars
});
