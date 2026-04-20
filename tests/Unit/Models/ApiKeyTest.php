<?php

use App\Http\Middleware\ApiKeyAuthentication;
use App\Models\ApiKey;
use App\Models\User;
use Filament\Panel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

test('api key belongs to user', function () {
    $user = User::factory()->create();
    $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);

    expect($apiKey->user)->toBeInstanceOf(User::class);
    expect($apiKey->user->id)->toBe($user->id);
});

test('api key requires name', function () {
    ApiKey::factory()->create(['name' => null]);
})->throws(QueryException::class);

test('api key requires key', function () {
    ApiKey::factory()->create(['key' => null]);
})->throws(QueryException::class);

test('api key has unique hash for the raw key value', function () {
    $key = ApiKey::generateKey();
    ApiKey::factory()->create(['key' => $key]);

    ApiKey::factory()->create(['key' => $key]);
})->throws(QueryException::class);

test('api key preview is not unique because uniqueness belongs to the hash', function () {
    $firstKey = 'ck_123'.str_repeat('a', 33).'wxyz';
    $secondKey = 'ck_123'.str_repeat('b', 33).'wxyz';

    $firstApiKey = ApiKey::factory()->create(['key' => $firstKey]);
    $secondApiKey = ApiKey::factory()->create(['key' => $secondKey]);

    expect($firstApiKey->getRawOriginal('key'))
        ->toBe($secondApiKey->getRawOriginal('key'))
        ->and($firstApiKey->getRawOriginal('key_hash'))
        ->not->toBe($secondApiKey->getRawOriginal('key_hash'));
});

test('api key stores only hashed credentials in the database', function () {
    $user = User::factory()->create();
    $plainTextKey = ApiKey::generateKey();

    $apiKey = ApiKey::create([
        'user_id' => $user->id,
        'name' => 'Production agent',
        'key' => $plainTextKey,
        'is_active' => true,
    ]);

    $storedApiKey = DB::table('api_keys')->where('id', $apiKey->id)->first();

    expect($apiKey->key)->toBe($plainTextKey)
        ->and($storedApiKey->key_hash)->toBe(ApiKey::hashKey($plainTextKey))
        ->and($storedApiKey->key)->not->toBe($plainTextKey)
        ->and($apiKey->fresh()->key)->toBeNull();
});

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
    $apiKey = ApiKey::generateKey();

    expect($apiKey)->toStartWith('ck_');
    expect(strlen($apiKey))->toBe(43); // ck_ + 40 chars
});

test('middleware authenticates requests against the hashed api key', function () {
    Route::middleware(ApiKeyAuthentication::class)->get('/_test/api-key-auth', function () {
        return response()->json([
            'user_id' => auth()->id(),
        ]);
    });

    $user = User::factory()->create();
    $apiKey = ApiKey::create([
        'user_id' => $user->id,
        'name' => 'Agent key',
        'key' => ApiKey::generateKey(),
        'is_active' => true,
    ]);

    $response = $this->withToken($apiKey->key)->getJson('/_test/api-key-auth');

    $response->assertOk()->assertJson([
        'user_id' => $user->id,
    ]);

    expect($apiKey->fresh()->last_used_at)->not->toBeNull();
});

test('middleware still accepts legacy plaintext api keys without a hash', function () {
    Route::middleware(ApiKeyAuthentication::class)->get('/_test/api-key-auth-legacy', function () {
        return response()->json([
            'user_id' => auth()->id(),
        ]);
    });

    $user = User::factory()->create();
    $plainTextKey = ApiKey::generateKey();

    DB::table('api_keys')->insert([
        'user_id' => $user->id,
        'name' => 'Legacy key',
        'key' => $plainTextKey,
        'key_hash' => null,
        'last_used_at' => null,
        'expires_at' => now()->addDay(),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->withToken($plainTextKey)->getJson('/_test/api-key-auth-legacy');

    $response->assertOk()->assertJson([
        'user_id' => $user->id,
    ]);
});

test('users need elevated panel access to enter filament', function () {
    $panel = app(Panel::class);

    expect(User::factory()->create()->canAccessPanel($panel))->toBeFalse()
        ->and($this->actingAsSuperAdmin()->canAccessPanel($panel))->toBeTrue();
});
