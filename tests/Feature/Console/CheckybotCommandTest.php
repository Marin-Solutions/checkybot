<?php

use Illuminate\Support\Facades\Cache;
use MarinSolutions\CheckybotLaravel\CheckRegistry;
use MarinSolutions\CheckybotLaravel\Facades\Checkybot;
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
