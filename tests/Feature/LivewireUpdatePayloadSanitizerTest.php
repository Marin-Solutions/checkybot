<?php

use App\Support\LivewireUpdatePayloadSanitizer;
use Filament\Actions\Action;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Livewire\DatabaseNotifications;
use Filament\Livewire\Notifications;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;

class LivewireUpdatePayloadTestLogin extends Login
{
    public bool $editProfileActionCalled = false;

    public function editProfileAction(): Action
    {
        return Action::make('editProfile')
            ->schema([
                TextInput::make('email'),
            ])
            ->action(function (): void {
                $this->editProfileActionCalled = true;
            });
    }
}

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

it('returns a successful Livewire response for a descendant update without a mounted login action', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $snapshot = Livewire::test(Login::class)->snapshot;

    $response = $this->postJson(route('livewire.update'), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => [
                'mountedActions.0.data.email' => '',
            ],
            'calls' => [],
        ]],
    ], [
        'X-Livewire' => 'true',
    ])->assertOk();

    assertLivewireSnapshotIsUsable($response->json('components.0.snapshot'));
});

it('returns a successful Livewire response for a string-keyed mounted action update', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $snapshot = Livewire::test(Login::class)->snapshot;

    $response = $this->postJson(route('livewire.update'), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => [
                'mountedActions.foo.name' => '',
            ],
            'calls' => [],
        ]],
    ], [
        'X-Livewire' => 'true',
    ])->assertOk();

    assertLivewireSnapshotIsUsable($response->json('components.0.snapshot'));
});

it('returns a successful Livewire response for a scalar mounted action update', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $snapshot = Livewire::test(Login::class)->snapshot;

    $response = $this->postJson(route('livewire.update'), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => [
                'mountedActions' => false,
            ],
            'calls' => [],
        ]],
    ], [
        'X-Livewire' => 'true',
    ])->assertOk();

    assertLivewireSnapshotIsUsable($response->json('components.0.snapshot'));
});

it('returns a successful Livewire response for a whole mounted action replacement with unsigned context', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $snapshot = Livewire::test(Login::class)->snapshot;

    $response = $this->postJson(route('livewire.update'), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => [
                'mountedActions' => [[
                    'name' => 'inventedAction',
                    'arguments' => [],
                    'context' => [
                        'schemaComponent' => 'missing-component',
                    ],
                    'data' => [],
                ]],
            ],
            'calls' => [],
        ]],
    ], [
        'X-Livewire' => 'true',
    ])->assertOk();

    assertLivewireSnapshotIsUsable($response->json('components.0.snapshot'));
});

it('keeps descendant form updates for an action present in the signed snapshot', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::component('livewire-update-payload-test-login', LivewireUpdatePayloadTestLogin::class);

    $snapshot = Livewire::test(LivewireUpdatePayloadTestLogin::class)
        ->mountAction('editProfile')
        ->snapshot;

    $payload = app(LivewireUpdatePayloadSanitizer::class)->sanitize([
        [
            'snapshot' => json_encode($snapshot),
            'updates' => [
                'mountedActions.0.data.email' => 'updated@example.com',
            ],
            'calls' => [],
        ],
    ]);

    expect($payload[0]['updates'])
        ->toHaveKey('mountedActions.0.data.email', 'updated@example.com');
});

it('keeps mounted action state resolvable when root and descendant updates are combined', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::component('livewire-update-payload-test-login', LivewireUpdatePayloadTestLogin::class);

    $snapshot = Livewire::test(LivewireUpdatePayloadTestLogin::class)
        ->mountAction('editProfile')
        ->snapshot;

    $response = $this->postJson(route('livewire.update'), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => [
                'mountedActions' => [],
                'mountedActions.0.data.email' => 'updated@example.com',
            ],
            'calls' => [],
        ]],
    ], [
        'X-Livewire' => 'true',
    ])->assertOk();

    $nextResponse = $this->postJson(route('livewire.update'), [
        'components' => [[
            'snapshot' => $response->json('components.0.snapshot'),
            'updates' => [],
            'calls' => [[
                'path' => '',
                'method' => 'callMountedAction',
                'params' => [],
            ]],
        ]],
    ], [
        'X-Livewire' => 'true',
    ])->assertOk();

    $nextSnapshot = json_decode($nextResponse->json('components.0.snapshot'), associative: true);

    expect($nextSnapshot['data']['editProfileActionCalled'])->toBeTrue();
});

it('returns a successful Livewire response when an action name uses the removal sentinel', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::component('livewire-update-payload-test-login', LivewireUpdatePayloadTestLogin::class);

    $snapshot = Livewire::test(LivewireUpdatePayloadTestLogin::class)
        ->mountAction('editProfile')
        ->snapshot;

    $response = $this->postJson(route('livewire.update'), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => [
                'mountedActions.0.name' => '__rm__',
            ],
            'calls' => [],
        ]],
    ], [
        'X-Livewire' => 'true',
    ])->assertOk();

    $nextResponse = $this->postJson(route('livewire.update'), [
        'components' => [[
            'snapshot' => $response->json('components.0.snapshot'),
            'updates' => [],
            'calls' => [[
                'path' => '',
                'method' => 'callMountedAction',
                'params' => [],
            ]],
        ]],
    ], [
        'X-Livewire' => 'true',
    ])->assertOk();

    $nextSnapshot = json_decode($nextResponse->json('components.0.snapshot'), associative: true);

    expect($nextSnapshot['data']['editProfileActionCalled'])->toBeTrue();
});

it('returns a successful Livewire response for a nameless login action update', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $snapshot = Livewire::test(Login::class)->snapshot;

    $response = $this->postJson(route('livewire.update'), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => [
                'mountedActions.0.name' => '',
            ],
            'calls' => [],
        ]],
    ], [
        'X-Livewire' => 'true',
    ])->assertOk();

    assertLivewireSnapshotIsUsable($response->json('components.0.snapshot'));
});
