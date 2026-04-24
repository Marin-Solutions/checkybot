<?php

use App\Support\PackageCheckTableEvidence;
use Carbon\Carbon;

test('freshness description uses stale_at when stale is flagged before threshold elapses', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subMinutes(2),
        'stale_at' => now()->subMinute(),
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Stale')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toBe('Expired 1 minute ago.');
});

test('freshness evidence stays stale when interval cannot be parsed but stale_at exists', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'package_interval' => 'every friday',
        'last_heartbeat_at' => now()->subMinutes(30),
        'stale_at' => now()->subMinutes(4),
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Stale')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toBe('Expired 4 minutes ago.');
});

test('freshness evidence marks unparsable intervals without stale_at as schedule unknown', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'package_interval' => 'every friday',
        'last_heartbeat_at' => now()->subMinutes(30),
        'stale_at' => null,
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Schedule unknown')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toBe('Package interval every friday cannot be evaluated.');
});

test('freshness evidence marks blank intervals as schedule unknown', function () {
    $record = (object) [
        'package_interval' => null,
        'last_heartbeat_at' => null,
        'stale_at' => null,
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Schedule unknown')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toBe('No package interval configured yet.');
});

test('freshness evidence treats disabled api monitors as disabled instead of stale', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'is_enabled' => false,
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subHour(),
        'stale_at' => now()->subMinutes(30),
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Disabled')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toBe('Monitor is disabled. Heartbeats are not expected.');
});

test('freshness evidence stays fresh at the exact stale boundary until backend stale detection triggers', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subMinutes(5),
        'stale_at' => null,
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Fresh')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toContain('Expires');
});

afterEach(function () {
    Carbon::setTestNow();
});
