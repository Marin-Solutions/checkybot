<?php

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
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

test('filament notifications hydrate already resolved notification payloads without type errors', function () {
    $synth = (new ReflectionClass(\App\Livewire\FilamentNotificationsCollectionSynth::class))
        ->newInstanceWithoutConstructor();

    $collection = $synth->hydrate(
        ['notification-2' => Notification::make('notification-2')->title('Queued')],
        ['class' => \Filament\Notifications\Collection::class],
        fn (string $key, mixed $value): mixed => $value,
    );

    expect($collection->get('notification-2'))
        ->toBeInstanceOf(Notification::class)
        ->and($collection->get('notification-2')->getTitle())
        ->toBe('Queued');
});

test('filament notifications discard malformed entries and unnamed actions while hydrating', function () {
    $synth = (new ReflectionClass(\App\Livewire\FilamentNotificationsCollectionSynth::class))
        ->newInstanceWithoutConstructor();

    $collection = $synth->hydrate(
        [
            'malformed' => 6000,
            'notification-3' => [
                'id' => 'notification-3',
                'title' => 'Saved',
                'actions' => [
                    ['label' => 'Missing name'],
                    ['name' => ['invalid'], 'label' => 'Invalid name'],
                    ['name' => 42, 'label' => 'Numeric name'],
                    ['name' => 'view', 'label' => 'View'],
                    [
                        'actions' => [
                            ['name' => 'grouped-view', 'label' => 'Grouped view'],
                            ['name' => null, 'label' => 'Nested missing name'],
                        ],
                    ],
                ],
            ],
        ],
        ['class' => \Filament\Notifications\Collection::class],
        fn (string $key, mixed $value): mixed => $value,
    );

    $notification = $collection->get('notification-3');

    expect($collection)->toHaveCount(1)
        ->and($notification)->toBeInstanceOf(Notification::class)
        ->and($notification->toArray()['actions'])->toHaveCount(3)
        ->and($notification->toArray()['actions'][0]['name'])->toBe('42')
        ->and($notification->toArray()['actions'][1]['name'])->toBe('view')
        ->and($notification->toArray()['actions'][2]['name'])->toBe('grouped-view');
});
