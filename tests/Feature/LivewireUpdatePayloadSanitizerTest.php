<?php

use App\Support\LivewireUpdatePayloadSanitizer;
use Filament\Livewire\DatabaseNotifications;
use Filament\Livewire\Notifications;
use Livewire\Mechanisms\ComponentRegistry;

it('drops client updates for locked Livewire properties before hydration', function () {
    $name = app(ComponentRegistry::class)->getName(DatabaseNotifications::class);

    $payload = app(LivewireUpdatePayloadSanitizer::class)->sanitize([
        [
            'snapshot' => json_encode(['memo' => ['name' => $name]]),
            'updates' => [
                'position' => [],
                'tableSearch' => 'timeout',
            ],
            'calls' => [],
        ],
    ]);

    expect($payload[0]['updates'])
        ->not->toHaveKey('position')
        ->toHaveKey('tableSearch', 'timeout');
});

it('drops invalid notification component flag updates before Livewire assigns typed properties', function () {
    $name = app(ComponentRegistry::class)->getName(Notifications::class);

    $payload = app(LivewireUpdatePayloadSanitizer::class)->sanitize([
        [
            'snapshot' => json_encode(['memo' => ['name' => $name]]),
            'updates' => [
                'isFilamentNotificationsComponent' => [],
                'notifications.notification-1' => ['title' => 'Saved'],
            ],
            'calls' => [],
        ],
    ]);

    expect($payload[0]['updates'])
        ->not->toHaveKey('isFilamentNotificationsComponent')
        ->toHaveKey('notifications.notification-1');
});
