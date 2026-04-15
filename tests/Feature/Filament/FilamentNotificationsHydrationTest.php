<?php

use Filament\Notifications\Livewire\Notifications;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;

test('filament notifications hydrate flattened livewire payloads without crashing', function () {
    $component = Livewire::test(Notifications::class)
        ->dispatch('notificationSent', notification: [
            'id' => 'notification-1',
            'title' => 'Saved',
            'duration' => 6000,
        ]);

    $snapshot = $component->snapshot;
    $notificationPayload = $snapshot['data']['notifications'][0]['notification-1'][0];

    $snapshot['data']['notifications'][0] = $notificationPayload;
    unset($snapshot['checksum']);
    $snapshot['checksum'] = Checksum::generate($snapshot);

    [$nextSnapshot] = app('livewire')->update($snapshot, [], []);

    expect($nextSnapshot['data']['notifications'][0])
        ->toHaveKey('notification-1');
});
