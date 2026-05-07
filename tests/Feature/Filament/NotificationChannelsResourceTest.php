<?php

use App\Filament\Resources\NotificationChannelsResource\Pages\CreateNotificationChannels;
use App\Filament\Resources\NotificationChannelsResource\Pages\EditNotificationChannels;
use App\Filament\Resources\NotificationChannelsResource\Pages\ListNotificationChannels;
use App\Models\NotificationChannels;
use App\Models\User;
use App\Policies\NotificationChannelsPolicy;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('super admin can create a post webhook channel with no request body', function () {
    $this->createResourcePermissions('NotificationChannels');

    $user = $this->actingAsSuperAdmin();

    Livewire::test(CreateNotificationChannels::class)
        ->fillForm([
            'title' => 'Incident webhook',
            'method' => 'POST',
            'url' => 'https://example.com/webhook?text={message}&description={description}',
            'request_body' => null,
            'description' => 'Receives incident alerts.',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $channel = NotificationChannels::query()->where('title', 'Incident webhook')->firstOrFail();

    expect($channel->created_by)->toBe($user->id)
        ->and($channel->method)->toBe('POST')
        ->and($channel->request_body)->toBe([]);
});

test('webhook channel list only shows channels created by the current user', function () {
    $user = $this->actingAsSuperAdmin();

    $ownChannel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'title' => 'Primary incident webhook',
    ]);
    $otherChannel = NotificationChannels::factory()->create([
        'title' => 'Other team webhook',
    ]);

    Livewire::test(ListNotificationChannels::class)
        ->assertCanSeeTableRecords([$ownChannel])
        ->assertCanNotSeeTableRecords([$otherChannel])
        ->assertSee('Primary incident webhook')
        ->assertDontSee('Other team webhook');
});

test('webhook channel policy denies another users channel even with channel permissions', function () {
    $this->createResourcePermissions('NotificationChannels');

    $user = User::factory()->create();
    $user->givePermissionTo([
        'View:NotificationChannels',
        'Update:NotificationChannels',
        'Delete:NotificationChannels',
        'Restore:NotificationChannels',
        'ForceDelete:NotificationChannels',
        'Replicate:NotificationChannels',
    ]);

    $ownChannel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
    ]);
    $otherChannel = NotificationChannels::factory()->create();
    $policy = new NotificationChannelsPolicy;

    expect($policy->view($user, $ownChannel))->toBeTrue()
        ->and($policy->update($user, $ownChannel))->toBeTrue()
        ->and($policy->delete($user, $ownChannel))->toBeTrue()
        ->and($policy->view($user, $otherChannel))->toBeFalse()
        ->and($policy->update($user, $otherChannel))->toBeFalse()
        ->and($policy->delete($user, $otherChannel))->toBeFalse()
        ->and($policy->restore($user, $otherChannel))->toBeFalse()
        ->and($policy->forceDelete($user, $otherChannel))->toBeFalse()
        ->and($policy->replicate($user, $otherChannel))->toBeFalse();
});

test('webhook channel list shows last delivery evidence', function () {
    $user = $this->actingAsSuperAdmin();

    $channel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'title' => 'Incident webhook',
        'last_delivery_kind' => 'send',
        'last_delivery_succeeded' => false,
        'last_delivery_response_code' => 502,
        'last_delivery_summary' => 'HTTP 502: upstream unavailable',
        'last_delivery_attempted_at' => now(),
    ]);

    Livewire::test(ListNotificationChannels::class)
        ->assertCanSeeTableRecords([$channel])
        ->assertSee('Failed send')
        ->assertSee('502')
        ->assertSee('HTTP 502: upstream unavailable');
});

test('editing a webhook channel records test evidence', function () {
    Http::fake([
        '*' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $user = $this->actingAsSuperAdmin();

    $channel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'method' => 'POST',
        'url' => 'https://example.com/webhook',
        'request_body' => [
            'message' => '{message}',
            'description' => '{description}',
        ],
    ]);

    Livewire::test(EditNotificationChannels::class, ['record' => $channel->getRouteKey()])
        ->fillForm([
            'title' => $channel->title,
            'method' => 'POST',
            'url' => 'https://example.com/webhook',
            'request_body' => [
                'message' => '{message}',
                'description' => '{description}',
            ],
            'description' => $channel->description,
        ])
        ->call('testWebhook')
        ->assertNotified('Webhook returned an error status');

    $channel->refresh();

    expect($channel->last_delivery_kind)->toBe('test');
    expect($channel->last_delivery_succeeded)->toBeFalse();
    expect($channel->last_delivery_response_code)->toBe(401);
    expect($channel->last_delivery_summary)->toContain('HTTP 401');
});
