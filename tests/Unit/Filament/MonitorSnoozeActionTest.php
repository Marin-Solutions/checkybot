<?php

use App\Filament\Resources\Support\MonitorSnoozeAction;

test('resolves the 1h preset to roughly one hour from now', function () {
    $until = MonitorSnoozeAction::resolveUntil(['duration' => '1h']);

    expect($until)->not->toBeNull()
        ->and(now()->diffInMinutes($until))->toBeGreaterThanOrEqual(55)
        ->and(now()->diffInMinutes($until))->toBeLessThanOrEqual(65);
});

test('resolves the 4h preset to roughly four hours from now', function () {
    $until = MonitorSnoozeAction::resolveUntil(['duration' => '4h']);

    expect($until)->not->toBeNull()
        ->and(now()->diffInHours($until))->toBeGreaterThanOrEqual(3)
        ->and(now()->diffInHours($until))->toBeLessThanOrEqual(5);
});

test('resolves the 24h preset to roughly one day from now', function () {
    $until = MonitorSnoozeAction::resolveUntil(['duration' => '24h']);

    expect($until)->not->toBeNull()
        ->and(now()->diffInHours($until))->toBeGreaterThanOrEqual(23)
        ->and(now()->diffInHours($until))->toBeLessThanOrEqual(25);
});

test('resolves a future custom datetime to that exact moment', function () {
    $target = now()->addDays(2)->startOfMinute();

    $until = MonitorSnoozeAction::resolveUntil([
        'duration' => 'custom',
        'until' => $target->toDateTimeString(),
    ]);

    expect($until?->equalTo($target))->toBeTrue();
});

test('rejects a past custom datetime by returning null', function () {
    $until = MonitorSnoozeAction::resolveUntil([
        'duration' => 'custom',
        'until' => now()->subHour()->toDateTimeString(),
    ]);

    expect($until)->toBeNull();
});

test('rejects a custom duration without an until value', function () {
    $until = MonitorSnoozeAction::resolveUntil(['duration' => 'custom']);

    expect($until)->toBeNull();
});

test('rejects an unrecognised duration preset by returning null', function () {
    $until = MonitorSnoozeAction::resolveUntil(['duration' => '7d']);

    expect($until)->toBeNull();
});

test('rejects an empty payload by returning null', function () {
    $until = MonitorSnoozeAction::resolveUntil([]);

    expect($until)->toBeNull();
});
