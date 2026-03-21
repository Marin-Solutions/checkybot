<?php

use Illuminate\Support\Facades\Cache;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Facades\Checkybot;
use MarinSolutions\CheckybotLaravel\Http\CheckybotClient;
use MarinSolutions\CheckybotLaravel\Support\Interval;

beforeEach(function () {
    config()->set('checkybot-laravel.api_key', 'test-api-key');
    config()->set('checkybot-laravel.project_id', '123');
    config()->set('checkybot-laravel.base_url', 'https://checkybot.test');

    Checkybot::flush();
    Cache::flush();
});

afterEach(function () {
    Checkybot::flush();
    Cache::flush();
});

test('sync command sends only due health component heartbeats with raw metrics and computed status', function () {
    $this->travelTo(now()->setDate(2026, 3, 21)->setTime(12, 0));

    Checkybot::component('queue')
        ->everyFiveMinutes()
        ->metric('pending_jobs', fn (): int => 144)
        ->warningWhen('>=', 100)
        ->dangerWhen('>=', 200);

    Checkybot::component('database')
        ->everyMinute()
        ->metric('reachable', fn (): bool => false)
        ->dangerWhen('===', false);

    $components = app(\MarinSolutions\CheckybotLaravel\CheckRegistry::class)->getComponents();

    expect($components)->toHaveCount(2)
        ->and($components[0]->toHeartbeatPayload(now()))->toMatchArray([
            'name' => 'queue',
            'interval' => '5m',
            'status' => 'warning',
            'metrics' => [
                'pending_jobs' => 144,
            ],
        ])
        ->and($components[1]->toHeartbeatPayload(now()))->toMatchArray([
            'name' => 'database',
            'interval' => '1m',
            'status' => 'danger',
            'metrics' => [
                'reachable' => false,
            ],
        ]);
});

test('sync command sends only due component heartbeats', function () {
    $this->travelTo(now()->setDate(2026, 3, 21)->setTime(12, 0));

    Checkybot::component('queue')
        ->everyFiveMinutes()
        ->metric('pending_jobs', fn (): int => 144)
        ->warningWhen('>=', 100)
        ->dangerWhen('>=', 200);

    Checkybot::component('database')
        ->everyMinute()
        ->metric('reachable', fn (): bool => false)
        ->dangerWhen('===', false);

    $registry = app(CheckRegistry::class);
    $components = $registry->getComponents();
    $currentTime = now();

    expect($components)->toHaveCount(2)
        ->and(Interval::isDue($components[0]->getInterval(), $currentTime->copy()->subMinute(), $currentTime->copy()))->toBeFalse()
        ->and(Interval::isDue($components[1]->getInterval(), $currentTime->copy()->subMinutes(2), $currentTime->copy()))->toBeTrue()
        ->and($components[1]->toHeartbeatPayload($currentTime))->toMatchArray([
            'name' => 'database',
            'interval' => '1m',
            'status' => 'danger',
            'metrics' => [
                'reachable' => false,
            ],
        ]);
});

test('sync command sends external checks from the registry alongside due components', function () {
    $this->travelTo(now()->setDate(2026, 3, 21)->setTime(12, 0));

    Checkybot::uptime('homepage')
        ->url('https://example.com')
        ->every('5m');

    Checkybot::ssl('certificate')
        ->url('https://example.com')
        ->every('1d');

    Checkybot::api('health')
        ->url('https://example.com/api/health')
        ->headers([
            'Accept' => 'application/json',
        ])
        ->every('5m')
        ->expectPathExists('status');

    Checkybot::component('queue')
        ->everyMinute()
        ->metric('pending_jobs', fn (): int => 12)
        ->warningWhen('>=', 50)
        ->dangerWhen('>=', 100);

    $fakeClient = new class extends CheckybotClient
    {
        public array $checkPayloads = [];

        public array $componentPayloads = [];

        public function __construct() {}

        public function syncChecks(array $payload): array
        {
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
                ],
            ],
            'ssl_checks' => [
                [
                    'name' => 'certificate',
                    'url' => 'https://example.com',
                    'interval' => '1d',
                ],
            ],
            'api_checks' => [
                [
                    'name' => 'health',
                    'url' => 'https://example.com/api/health',
                    'interval' => '5m',
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                    'assertions' => [
                        [
                            'data_path' => 'status',
                            'assertion_type' => 'exists',
                            'sort_order' => 1,
                            'is_active' => true,
                        ],
                    ],
                ],
            ],
        ])
        ->and($fakeClient->componentPayloads)->toHaveCount(1)
        ->and($fakeClient->componentPayloads[0]['components'][0])->toMatchArray([
            'name' => 'queue',
            'interval' => '1m',
            'status' => 'healthy',
            'metrics' => [
                'pending_jobs' => 12,
            ],
        ]);
});

test('sync command posts an empty external check payload so package-managed removals can be pruned', function () {
    $fakeClient = new class extends CheckybotClient
    {
        public array $checkPayloads = [];

        public function __construct() {}

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
    ]);
});
