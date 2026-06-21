<?php

use App\Models\MonitorApis;
use App\Models\Website;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('command can be executed', function () {
    $this->artisan('monitor-actions:expire-stuck')
        ->expectsOutput('Expired 0 stuck monitor actions (0 website diagnostics, 0 API diagnostics, 0 outbound scans).')
        ->assertSuccessful();
});

test('command expires stale queued website and api diagnostics', function () {
    Carbon::setTestNow('2026-05-08 12:00:00');

    $website = Website::factory()->create([
        'diagnostic_queued_at' => now()->subMinutes(31),
    ]);
    $monitor = MonitorApis::factory()->create([
        'diagnostic_queued_at' => now()->subMinutes(31),
    ]);

    $this->artisan('monitor-actions:expire-stuck')
        ->expectsOutput('Expired 2 stuck monitor actions (1 website diagnostics, 1 API diagnostics, 0 outbound scans).')
        ->assertSuccessful();

    expect($website->refresh()->diagnostic_queued_at)->toBeNull()
        ->and($monitor->refresh()->diagnostic_queued_at)->toBeNull();
});

test('command expires stale queued outbound scans', function () {
    Carbon::setTestNow('2026-05-08 12:00:00');

    $website = Website::factory()->create([
        'outbound_scan_queued_at' => now()->subMinutes(121),
    ]);

    $this->artisan('monitor-actions:expire-stuck')
        ->expectsOutput('Expired 1 stuck monitor actions (0 website diagnostics, 0 API diagnostics, 1 outbound scans).')
        ->assertSuccessful();

    expect($website->refresh()->outbound_scan_queued_at)->toBeNull();
});

test('command leaves recent queued monitor actions untouched', function () {
    Carbon::setTestNow('2026-05-08 12:00:00');

    $websiteDiagnostic = Website::factory()->create([
        'diagnostic_queued_at' => now()->subMinutes(29),
    ]);
    $apiDiagnostic = MonitorApis::factory()->create([
        'diagnostic_queued_at' => now()->subMinutes(29),
    ]);
    $outboundScan = Website::factory()->create([
        'outbound_scan_queued_at' => now()->subMinutes(119),
    ]);

    $this->artisan('monitor-actions:expire-stuck')
        ->expectsOutput('Expired 0 stuck monitor actions (0 website diagnostics, 0 API diagnostics, 0 outbound scans).')
        ->assertSuccessful();

    expect($websiteDiagnostic->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-08 11:31:00')
        ->and($apiDiagnostic->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-08 11:31:00')
        ->and($outboundScan->refresh()->outbound_scan_queued_at?->toDateTimeString())->toBe('2026-05-08 10:01:00');
});

test('command supports custom expiration thresholds', function () {
    Carbon::setTestNow('2026-05-08 12:00:00');

    $websiteDiagnostic = Website::factory()->create([
        'diagnostic_queued_at' => now()->subMinutes(6),
    ]);
    $apiDiagnostic = MonitorApis::factory()->create([
        'diagnostic_queued_at' => now()->subMinutes(6),
    ]);
    $outboundScan = Website::factory()->create([
        'outbound_scan_queued_at' => now()->subMinutes(11),
    ]);

    $this->artisan('monitor-actions:expire-stuck --diagnostic-minutes=5 --outbound-minutes=10')
        ->expectsOutput('Expired 3 stuck monitor actions (1 website diagnostics, 1 API diagnostics, 1 outbound scans).')
        ->assertSuccessful();

    expect($websiteDiagnostic->refresh()->diagnostic_queued_at)->toBeNull()
        ->and($apiDiagnostic->refresh()->diagnostic_queued_at)->toBeNull()
        ->and($outboundScan->refresh()->outbound_scan_queued_at)->toBeNull();
});

test('command rejects non positive expiration thresholds', function () {
    $this->artisan('monitor-actions:expire-stuck --diagnostic-minutes=0')
        ->assertFailed();
});

test('scheduled monitor action expiration command uses overlap protection', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event) => str_contains((string) $event->command, 'monitor-actions:expire-stuck')
    );

    expect($event)->not->toBeNull();
    expect($event->withoutOverlapping)->toBeTrue();
});

test('scheduled monitor action expiration runs before api monitor checks', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->values();

    $expireIndex = $commands->search(fn (string $command) => str_contains($command, 'monitor-actions:expire-stuck'));
    $apiMonitorIndex = $commands->search(fn (string $command) => str_contains($command, 'monitor:check-apis'));

    expect($expireIndex)->not->toBeFalse()
        ->and($apiMonitorIndex)->not->toBeFalse()
        ->and($expireIndex)->toBeLessThan($apiMonitorIndex);
});
