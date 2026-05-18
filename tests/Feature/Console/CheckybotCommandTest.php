<?php

use Illuminate\Support\Facades\Cache;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Facades\Checkybot;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;
use MarinSolutions\CheckybotLaravel\Support\Interval;

beforeEach(function () {
    config()->set('checkybot-laravel.api_key', 'test-api-key');
    config()->set('checkybot-laravel.base_url', 'https://checkybot.test');
    config()->set('checkybot-laravel.app_id', null);
    config()->set('checkybot-laravel.application_name', 'Checkout App');
    config()->set('checkybot-laravel.environment', 'production');
    config()->set('checkybot-laravel.identity_endpoint', 'https://checkout.example.com');

    Checkybot::flush();
    Cache::flush();
});

afterEach(function () {
    Checkybot::flush();
    Cache::flush();
});

test('sync command registers declared health components without runtime heartbeat metrics', function () {
    $this->travelTo(now()->setDate(2026, 3, 21)->setTime(12, 0));

    Checkybot::component('queue')
        ->everyFiveMinutes();

    Checkybot::component('database')
        ->everyMinute();

    $components = app(\MarinSolutions\CheckybotLaravel\CheckRegistry::class)->getComponents();

    expect($components)->toHaveCount(2)
        ->and($components[0]->toArray())->toBe([
            'name' => 'queue',
            'interval' => '5m',
        ])
        ->and($components[1]->toArray())->toBe([
            'name' => 'database',
            'interval' => '1m',
        ]);
});

test('sync command keeps component intervals as declarations only', function () {
    $this->travelTo(now()->setDate(2026, 3, 21)->setTime(12, 0));

    Checkybot::component('queue')
        ->everyFiveMinutes();

    Checkybot::component('database')
        ->everyMinute();

    $registry = app(CheckRegistry::class);
    $components = $registry->getComponents();
    $currentTime = now();

    expect($components)->toHaveCount(2)
        ->and(Interval::isDue($components[0]->getInterval(), $currentTime->copy()->subMinute(), $currentTime->copy()))->toBeFalse()
        ->and(Interval::isDue($components[1]->getInterval(), $currentTime->copy()->subMinutes(2), $currentTime->copy()))->toBeTrue()
        ->and($components[1]->toArray())->toBe([
            'name' => 'database',
            'interval' => '1m',
        ]);
});

test('sync command sends the full declared component schema without heartbeats', function () {
    $this->travelTo(now()->setDate(2026, 3, 21)->setTime(12, 0));

    Checkybot::component('queue')
        ->everyFiveMinutes();

    Checkybot::component('database')
        ->everyMinute();

    Cache::forever(
        'checkybot-laravel.components.identity:'.sha1('production|https://checkout.example.com').'.queue.last_reported_at',
        now()->copy()->subMinute()->toISOString()
    );

    $fakeClient = new class extends CheckybotClient
    {
        public array $componentPayloads = [];

        public function __construct() {}

        public function registerApplication(array $payload): array
        {
            $this->projectId = '123';

            return [
                'data' => [
                    'project_id' => 123,
                    'created' => false,
                ],
            ];
        }

        public function syncChecks(array $payload): array
        {
            return [
                'summary' => [
                    'uptime_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                    'ssl_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                    'api_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                ],
            ];
        }

        public function syncComponents(array $payload): array
        {
            $this->componentPayloads[] = $payload;

            return [];
        }
    };

    app()->instance(CheckybotClient::class, $fakeClient);

    $this->artisan('checkybot:sync')
        ->assertExitCode(0);

    expect($fakeClient->componentPayloads)->toHaveCount(1)
        ->and($fakeClient->componentPayloads[0]['full_manifest'])->toBeTrue()
        ->and($fakeClient->componentPayloads[0]['declared_components'])->toMatchArray([
            [
                'name' => 'queue',
                'interval' => '5m',
            ],
            [
                'name' => 'database',
                'interval' => '1m',
            ],
        ])
        ->and($fakeClient->componentPayloads[0])->not->toHaveKey('components');
});

test('dry run shows declared components without runtime component heartbeats', function () {
    $this->travelTo(now()->setDate(2026, 3, 21)->setTime(12, 0));

    Checkybot::component('queue')
        ->everyFiveMinutes();

    Checkybot::component('database')
        ->everyMinute();

    Cache::forever(
        'checkybot-laravel.components.identity:'.sha1('production|https://checkout.example.com').'.queue.last_reported_at',
        now()->copy()->subMinute()->toISOString()
    );

    $this->artisan('checkybot:sync --dry-run')
        ->expectsOutput('Checkybot Sync Starting...')
        ->expectsOutput('Found 0 checks to sync and 2 components to declare')
        ->expectsOutput('DRY RUN - No changes will be made')
        ->expectsOutput('Declared Components (2):')
        ->expectsOutput('  - queue every 5m')
        ->expectsOutput('  - database every 1m')
        ->doesntExpectOutput('Due Component Heartbeats (1):')
        ->doesntExpectOutput('Components:')
        ->assertExitCode(0);
});

test('sync command sends external checks from the registry alongside due components', function () {
    $this->travelTo(now()->setDate(2026, 3, 21)->setTime(12, 0));

    Checkybot::uptime('homepage')
        ->url('https://example.com')
        ->component('queue')
        ->every('5m');

    Checkybot::ssl('certificate')
        ->url('https://example.com')
        ->component('queue')
        ->every('1d');

    Checkybot::api('health')
        ->url('https://example.com/api/health')
        ->component('queue')
        ->headers([
            'Accept' => 'application/json',
        ])
        ->dontSaveFailedResponse()
        ->every('5m')
        ->expectPathExists('status');

    Checkybot::api('status')
        ->url('https://example.com/api/status')
        ->saveFailedResponse()
        ->every('5m');

    Checkybot::component('queue')
        ->everyMinute();

    $fakeClient = new class extends CheckybotClient
    {
        public array $registrationPayloads = [];

        public array $checkPayloads = [];

        public array $componentPayloads = [];

        public array $events = [];

        public function __construct() {}

        public function registerApplication(array $payload): array
        {
            $this->registrationPayloads[] = $payload;
            $this->projectId = '123';

            return [
                'data' => [
                    'project_id' => 123,
                    'created' => false,
                ],
            ];
        }

        public function syncChecks(array $payload): array
        {
            $this->events[] = 'checks';
            $this->checkPayloads[] = $payload;

            return [
                'summary' => [
                    'uptime_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                    'ssl_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                    'api_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                ],
            ];
        }

        public function syncComponents(array $payload): array
        {
            $this->events[] = 'components';
            $this->componentPayloads[] = $payload;

            return [];
        }
    };

    app()->instance(CheckybotClient::class, $fakeClient);

    $this->artisan('checkybot:sync')
        ->assertExitCode(0);

    expect($fakeClient->checkPayloads)->toHaveCount(1)
        ->and($fakeClient->checkPayloads[0])->toMatchArray([
            'uptime_checks' => [
                [
                    'name' => 'homepage',
                    'url' => 'https://example.com',
                    'interval' => '5m',
                    'component' => 'queue',
                ],
            ],
            'ssl_checks' => [
                [
                    'name' => 'certificate',
                    'url' => 'https://example.com',
                    'interval' => '1d',
                    'component' => 'queue',
                ],
            ],
            'api_checks' => [
                [
                    'name' => 'health',
                    'url' => 'https://example.com/api/health',
                    'interval' => '5m',
                    'component' => 'queue',
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                    'save_failed_response' => false,
                    'assertions' => [
                        [
                            'data_path' => 'status',
                            'assertion_type' => 'exists',
                            'sort_order' => 1,
                            'is_active' => true,
                        ],
                    ],
                ],
                [
                    'name' => 'status',
                    'url' => 'https://example.com/api/status',
                    'interval' => '5m',
                    'save_failed_response' => true,
                ],
            ],
        ])
        ->and($fakeClient->componentPayloads)->toHaveCount(1)
        ->and($fakeClient->events)->toBe(['components', 'checks'])
        ->and($fakeClient->componentPayloads[0]['declared_components'][0])->toMatchArray([
            'name' => 'queue',
            'interval' => '1m',
        ])
        ->and($fakeClient->componentPayloads[0])->not->toHaveKey('components');
});

test('sync command rejects checks that reference undeclared components', function () {
    Checkybot::uptime('homepage')
        ->url('https://example.com')
        ->component('queeu')
        ->every('5m');

    Checkybot::component('queue')
        ->everyMinute();

    $this->artisan('checkybot:sync')
        ->expectsOutput('Configuration validation failed:')
        ->expectsOutput('  - The uptime check "homepage" references undeclared component "queeu". Declare it with Checkybot::component(\'queeu\') or fix the component name.')
        ->assertExitCode(1);
});

test('sync command posts an empty external check payload so package-managed removals can be pruned', function () {
    $fakeClient = new class extends CheckybotClient
    {
        public array $registrationPayloads = [];

        public array $checkPayloads = [];

        public array $componentPayloads = [];

        public function __construct() {}

        public function registerApplication(array $payload): array
        {
            $this->registrationPayloads[] = $payload;
            $this->projectId = '123';

            return [
                'data' => [
                    'project_id' => 123,
                    'created' => false,
                ],
            ];
        }

        public function syncChecks(array $payload): array
        {
            $this->checkPayloads[] = $payload;

            return [
                'summary' => [
                    'uptime_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                    'ssl_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                    'api_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                ],
            ];
        }

        public function syncComponents(array $payload): array
        {
            $this->componentPayloads[] = $payload;

            return [];
        }
    };

    app()->instance(CheckybotClient::class, $fakeClient);

    $this->artisan('checkybot:sync')
        ->assertExitCode(0);

    expect($fakeClient->checkPayloads)->toBe([
        [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [],
        ],
    ])->and($fakeClient->componentPayloads)->toBe([
        [
            'full_manifest' => true,
            'declared_components' => [],
        ],
    ]);
});

test('sync command registers a guided setup application before syncing package data', function () {
    config()->set('checkybot-laravel.project_id', null);
    config()->set('checkybot-laravel.app_id', '987');

    Checkybot::uptime('homepage')
        ->url('https://example.com')
        ->every('5m');

    $fakeClient = new class extends CheckybotClient
    {
        public array $registrationPayloads = [];

        public array $checkPayloads = [];

        public array $componentPayloads = [];

        public ?string $projectIdAtSync = null;

        public function __construct() {}

        public function registerApplication(array $payload): array
        {
            $this->registrationPayloads[] = $payload;
            $this->projectId = '321';

            return [
                'data' => [
                    'project_id' => 321,
                    'created' => false,
                ],
            ];
        }

        public function syncChecks(array $payload): array
        {
            $this->projectIdAtSync = $this->projectId;
            $this->checkPayloads[] = $payload;

            return [
                'summary' => [
                    'uptime_checks' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
                    'ssl_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                    'api_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                ],
            ];
        }

        public function syncComponents(array $payload): array
        {
            $this->componentPayloads[] = $payload;

            return [];
        }
    };

    app()->instance(CheckybotClient::class, $fakeClient);

    $this->artisan('checkybot:sync')
        ->assertExitCode(0);

    expect($fakeClient->registrationPayloads)->toHaveCount(1)
        ->and($fakeClient->registrationPayloads[0])->toMatchArray([
            'app_id' => '987',
            'name' => 'Checkout App',
            'environment' => 'production',
            'identity_endpoint' => 'https://checkout.example.com',
            'package_version' => '0.1.0',
        ])
        ->and($fakeClient->projectIdAtSync)->toBe('321')
        ->and($fakeClient->checkPayloads)->toHaveCount(1)
        ->and($fakeClient->componentPayloads)->toBe([
            [
                'full_manifest' => true,
                'declared_components' => [],
            ],
        ]);
});

test('sync command can bootstrap without a guided app id and still sync package data', function () {
    config()->set('checkybot-laravel.project_id', null);
    config()->set('checkybot-laravel.app_id', null);

    Checkybot::component('queue')
        ->everyMinute();

    $fakeClient = new class extends CheckybotClient
    {
        public array $registrationPayloads = [];

        public array $componentPayloads = [];

        public ?string $projectIdAtComponentSync = null;

        public function __construct() {}

        public function registerApplication(array $payload): array
        {
            $this->registrationPayloads[] = $payload;
            $this->projectId = '654';

            return [
                'data' => [
                    'project_id' => 654,
                    'created' => true,
                ],
            ];
        }

        public function syncChecks(array $payload): array
        {
            return [
                'summary' => [
                    'uptime_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                    'ssl_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                    'api_checks' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
                ],
            ];
        }

        public function syncComponents(array $payload): array
        {
            $this->projectIdAtComponentSync = $this->projectId;
            $this->componentPayloads[] = $payload;

            return [];
        }
    };

    app()->instance(CheckybotClient::class, $fakeClient);

    $this->artisan('checkybot:sync')
        ->assertExitCode(0);

    expect($fakeClient->registrationPayloads)->toHaveCount(1)
        ->and($fakeClient->registrationPayloads[0])->not->toHaveKey('app_id')
        ->and($fakeClient->projectIdAtComponentSync)->toBe('654')
        ->and($fakeClient->componentPayloads)->toHaveCount(1);
});
