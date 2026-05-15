<?php

use App\Support\PackageCheckTableEvidence;
use Carbon\Carbon;

test('freshness evidence ignores legacy stale_at when a real run is still inside its interval', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'package_interval' => '5m',
        'latestScheduledResult' => (object) ['created_at' => now()->subMinutes(2)],
        'stale_at' => now()->subMinute(),
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Fresh')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toBe('Expires 3 minutes from now.');
});

test('freshness evidence treats unparsable intervals as unknown even when legacy stale_at exists', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'package_interval' => 'every friday',
        'latestScheduledResult' => (object) ['created_at' => now()->subMinutes(30)],
        'stale_at' => now()->subMinutes(4),
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Schedule unknown')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toBe('Package interval every friday cannot be evaluated.');
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

test('display interval uses scheduler rounded minutes for second based intervals', function () {
    expect(PackageCheckTableEvidence::displayInterval('30s'))->toBe('1m')
        ->and(PackageCheckTableEvidence::displayInterval('90s'))->toBe('2m')
        ->and(PackageCheckTableEvidence::displayInterval('every_30_seconds'))->toBe('1m');
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

test('freshness evidence uses created at while waiting for the first scheduled check', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'package_interval' => '5m',
        'last_heartbeat_at' => null,
        'created_at' => now()->subMinutes(2),
        'stale_at' => null,
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Awaiting check')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toBe('Expected every 5m.')
        ->and(PackageCheckTableEvidence::dueState($record))->toBe('Awaiting first run')
        ->and(PackageCheckTableEvidence::staleThresholdAt($record)?->toDateTimeString())->toBe('2026-04-24 12:03:00');
});

test('freshness evidence marks never-run package checks due after their first missed interval', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'package_interval' => '5m',
        'last_heartbeat_at' => null,
        'created_at' => now()->subMinutes(7),
        'stale_at' => null,
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Due now')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toBe('Due 2 minutes ago.')
        ->and(PackageCheckTableEvidence::dueState($record))->toBe('Due now')
        ->and(PackageCheckTableEvidence::dueDescription($record))->toContain('Overdue 2 minutes ago');
});

test('freshness evidence ignores legacy awaiting heartbeat reset time', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'package_interval' => '5m',
        'last_heartbeat_at' => null,
        'awaiting_heartbeat_since' => now()->subMinutes(2),
        'created_at' => now()->subHour(),
        'stale_at' => null,
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Due now')
        ->and(PackageCheckTableEvidence::staleThresholdAt($record)?->toDateTimeString())->toBe('2026-04-24 11:05:00')
        ->and(PackageCheckTableEvidence::dueState($record))->toBe('Due now');
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
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toBe('Monitor is disabled. Scheduled checks are not expected.');
});

test('freshness evidence treats package websites with no enabled checks as disabled', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'uptime_check' => false,
        'ssl_check' => false,
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subHour(),
        'stale_at' => now()->subMinutes(30),
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Disabled')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toBe('Monitor is disabled. Scheduled checks are not expected.')
        ->and(PackageCheckTableEvidence::dueState($record))->toBe('Paused')
        ->and(PackageCheckTableEvidence::dueDescription($record))->toBe('Scheduled checks are paused until this monitor is re-enabled.');
});

test('freshness evidence does not disable package websites while one check remains enabled', function (bool $uptimeCheck, bool $sslCheck) {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'uptime_check' => $uptimeCheck,
        'ssl_check' => $sslCheck,
        'package_interval' => '5m',
        'latestScheduledLogHistory' => (object) ['created_at' => now()->subMinute()],
        'stale_at' => null,
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Fresh')
        ->and(PackageCheckTableEvidence::dueState($record))->toBe('Scheduled');
})->with([
    'uptime only' => [true, false],
    'ssl only' => [false, true],
]);

test('freshness evidence stays fresh at the exact stale boundary until backend stale detection triggers', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $record = (object) [
        'package_interval' => '5m',
        'latestScheduledResult' => (object) ['created_at' => now()->subMinutes(5)],
        'stale_at' => null,
    ];

    expect(PackageCheckTableEvidence::freshnessState($record))->toBe('Fresh')
        ->and(PackageCheckTableEvidence::freshnessDescription($record))->toContain('Expires');
});

test('due description flags missing schedules as risky legacy behavior', function () {
    $record = (object) [
        'is_enabled' => true,
        'package_interval' => null,
        'last_heartbeat_at' => now(),
        'stale_at' => null,
    ];

    expect(PackageCheckTableEvidence::dueState($record))->toBe('Schedule required')
        ->and(PackageCheckTableEvidence::dueDescription($record))->toContain('No polling interval is configured');
});

test('due description marks overdue monitors as due now', function () {
    $record = (object) [
        'is_enabled' => true,
        'package_interval' => '5m',
        'latestScheduledResult' => (object) ['created_at' => now()->subMinutes(7)],
        'stale_at' => null,
    ];

    expect(PackageCheckTableEvidence::dueState($record))->toBe('Due now')
        ->and(PackageCheckTableEvidence::dueDescription($record))->toContain('Overdue')
        ->and(PackageCheckTableEvidence::dueDescription($record))->toContain('Expected every 5m');
});

afterEach(function () {
    Carbon::setTestNow();
});
