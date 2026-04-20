<?php

use App\Filament\Resources\ApiKeyResource;
use App\Filament\Resources\ApiKeyResource\Pages\CreateApiKey;
use App\Filament\Resources\ApiKeyResource\Pages\EditApiKey;
use App\Filament\Resources\ApiKeyResource\Pages\ListApiKeys;
use App\Filament\Resources\ServerResource;
use App\Filament\Resources\WebsiteResource;
use App\Models\ApiKey;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function latestApiKeyNotificationKey(): string
{
    $notification = collect(session('filament.notifications'))
        ->last(fn (array $notification): bool => str_contains($notification['body'] ?? '', 'ck_'));

    preg_match('/ck_[A-Za-z0-9]+/', $notification['body'] ?? '', $matches);

    expect($matches[0] ?? null)->toStartWith('ck_');

    return $matches[0];
}

test('super admin can render list page', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(ListApiKeys::class)
        ->assertSuccessful();
});

test('super admin can see own api keys in table', function () {
    $user = $this->actingAsSuperAdmin();
    $apiKeys = ApiKey::factory()->count(3)->create(['user_id' => $user->id]);

    Livewire::test(ListApiKeys::class)
        ->assertCanSeeTableRecords($apiKeys);
});

test('super admin cannot see other users api keys', function () {
    $user = $this->actingAsSuperAdmin();
    $ownApiKey = ApiKey::factory()->create(['user_id' => $user->id]);
    $otherApiKey = ApiKey::factory()->create(); // Different user

    Livewire::test(ListApiKeys::class)
        ->assertCanSeeTableRecords([$ownApiKey])
        ->assertCanNotSeeTableRecords([$otherApiKey]);
});

test('super admin can search api keys', function () {
    $user = $this->actingAsSuperAdmin();
    $apiKey1 = ApiKey::factory()->create(['name' => 'Production API Key', 'user_id' => $user->id]);
    $apiKey2 = ApiKey::factory()->create(['name' => 'Development Key', 'user_id' => $user->id]);

    Livewire::test(ListApiKeys::class)
        ->searchTable('Production')
        ->assertCanSeeTableRecords([$apiKey1])
        ->assertCanNotSeeTableRecords([$apiKey2]);
});

test('super admin can render create page', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(CreateApiKey::class)
        ->assertSuccessful();
});

test('super admin can create api key', function () {
    $user = $this->actingAsSuperAdmin();

    $component = Livewire::test(CreateApiKey::class)
        ->fillForm([
            'name' => 'Test API Key',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $generatedKey = latestApiKeyNotificationKey();
    $apiKey = ApiKey::query()->where('name', 'Test API Key')->firstOrFail();
    $storedApiKey = DB::table('api_keys')->where('id', $apiKey->id)->first();

    $this->assertDatabaseHas('api_keys', [
        'name' => 'Test API Key',
        'user_id' => $user->id,
        'is_active' => true,
    ]);

    expect($generatedKey)->toStartWith('ck_')
        ->and($storedApiKey->key_hash)->toBe(ApiKey::hashKey($generatedKey))
        ->and($storedApiKey->key)->not->toBe($generatedKey)
        ->and($component->get('generatedKey'))->toBeNull();

    Notification::assertNotified(ApiKeyResource::apiKeyCreatedNotification($generatedKey));
});

test('super admin can create api key from list and see it once', function () {
    $user = $this->actingAsSuperAdmin();

    $component = Livewire::test(ListApiKeys::class)
        ->callAction('create', data: [
            'name' => 'List API Key',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $generatedKey = latestApiKeyNotificationKey();
    $apiKey = ApiKey::query()->where('name', 'List API Key')->firstOrFail();
    $storedApiKey = DB::table('api_keys')->where('id', $apiKey->id)->first();

    $this->assertDatabaseHas('api_keys', [
        'name' => 'List API Key',
        'user_id' => $user->id,
        'is_active' => true,
    ]);

    expect($generatedKey)->toStartWith('ck_')
        ->and($storedApiKey->key_hash)->toBe(ApiKey::hashKey($generatedKey))
        ->and($storedApiKey->key)->not->toBe($generatedKey)
        ->and($component->get('generatedKey'))->toBeNull();

    Notification::assertNotified(ApiKeyResource::apiKeyCreatedNotification($generatedKey));
});

test('create api key requires name', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(CreateApiKey::class)
        ->fillForm([
            'name' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

test('super admin can render edit page', function () {
    $user = $this->actingAsSuperAdmin();
    $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);

    Livewire::test(EditApiKey::class, ['record' => $apiKey->id])
        ->assertSuccessful();
});

test('super admin can update api key', function () {
    $user = $this->actingAsSuperAdmin();
    $apiKey = ApiKey::factory()->create([
        'name' => 'Old Name',
        'user_id' => $user->id,
    ]);

    Livewire::test(EditApiKey::class, ['record' => $apiKey->id])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('api_keys', [
        'id' => $apiKey->id,
        'name' => 'New Name',
    ]);
});

test('super admin can delete api key', function () {
    $user = $this->actingAsSuperAdmin();
    $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);

    Livewire::test(EditApiKey::class, ['record' => $apiKey->id])
        ->callAction('delete');

    $this->assertDatabaseMissing('api_keys', [
        'id' => $apiKey->id,
    ]);
});

test('api key list does not expose plaintext keys', function () {
    $user = $this->actingAsSuperAdmin();
    $plainTextKey = ApiKey::generateKey();
    $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);
    $legacyApiKeyId = DB::table('api_keys')->insertGetId([
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
    $legacyApiKey = ApiKey::query()->findOrFail($legacyApiKeyId);

    Livewire::test(ListApiKeys::class)
        ->assertCanSeeTableRecords([$apiKey, $legacyApiKey])
        ->assertDontSee($plainTextKey)
        ->assertSee($apiKey->getRawOriginal('key'))
        ->assertSee('Legacy key hidden')
        ->assertSee('Masked preview. Full key shown once on creation.');
});

test('regular user cannot access the panel or protected resources', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $panel = app(Panel::class);

    expect($user->canAccessPanel($panel))->toBeFalse()
        ->and(ApiKeyResource::canViewAny())->toBeFalse()
        ->and(WebsiteResource::canViewAny())->toBeFalse()
        ->and(ServerResource::canViewAny())->toBeFalse();
});
