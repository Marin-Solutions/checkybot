<?php

use App\Support\LivewireUpdatePayloadSanitizer;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Filament\Livewire\Notifications;
use Livewire\Livewire;
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

it('drops nameless mounted action updates before Filament resolves them', function (array $updates) {
    $name = app(ComponentRegistry::class)->getName(Login::class);

    $payload = app(LivewireUpdatePayloadSanitizer::class)->sanitize([
        [
            'snapshot' => json_encode(['memo' => ['name' => $name]]),
            'updates' => $updates,
            'calls' => [],
        ],
    ]);

    expect($payload[0]['updates'])->toBe([]);
})->with([
    'nested name update' => [['mountedActions.0.name' => '']],
    'action update without name' => [['mountedActions.0' => ['arguments' => []]]],
    'action list update with blank name' => [['mountedActions' => [['name' => null]]]],
]);

it('keeps valid mounted action form updates', function () {
    $name = app(ComponentRegistry::class)->getName(Login::class);

    $payload = app(LivewireUpdatePayloadSanitizer::class)->sanitize([
        [
            'snapshot' => json_encode(['memo' => ['name' => $name]]),
            'updates' => [
                'mountedActions.0.data.email' => '',
            ],
            'calls' => [],
        ],
    ]);

    expect($payload[0]['updates'])
        ->toHaveKey('mountedActions.0.data.email', '');
});

it('returns a successful Livewire response for a nameless login action update', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $snapshot = Livewire::test(Login::class)->snapshot;

    $this->postJson(route('livewire.update'), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => [
                'mountedActions.0.name' => '',
            ],
            'calls' => [],
        ]],
    ], [
        'X-Livewire' => 'true',
    ])
        ->assertOk()
        ->assertJsonPath('components.0.snapshot', fn (string $snapshot): bool => str_contains($snapshot, 'filament.auth.pages.login'));
});
