<?php

use App\Filament\Resources\NotificationChannelsResource\Pages\CreateNotificationChannels;
use App\Filament\Resources\NotificationChannelsResource\Pages\ListNotificationChannels;
use App\Models\NotificationChannels;
use App\Models\User;
use App\Policies\NotificationChannelsPolicy;
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
