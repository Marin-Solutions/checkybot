<?php

use App\Filament\Resources\MonitorApisResource\Pages\ListMonitorApis;
use App\Filament\Resources\WebsiteResource\Pages\ListWebsites;
use App\Models\MonitorApis;
use App\Models\Website;
use Livewire\Livewire;

test('super admin can snooze a website for 1 hour via the row action', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    Livewire::test(ListWebsites::class)
        ->callTableAction('snooze', $website, data: [
            'duration' => '1h',
        ])
        ->assertHasNoTableActionErrors();

    $website->refresh();

    expect($website->silenced_until)->not->toBeNull()
        ->and($website->silenced_until->isFuture())->toBeTrue()
        ->and(now()->diffInMinutes($website->silenced_until))->toBeGreaterThanOrEqual(55)
        ->and(now()->diffInMinutes($website->silenced_until))->toBeLessThanOrEqual(65);
});

test('super admin can snooze a website for 24 hours via the row action', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    Livewire::test(ListWebsites::class)
        ->callTableAction('snooze', $website, data: [
            'duration' => '24h',
        ])
        ->assertHasNoTableActionErrors();

    $website->refresh();

    expect(now()->diffInHours($website->silenced_until))->toBeGreaterThanOrEqual(23)
        ->and(now()->diffInHours($website->silenced_until))->toBeLessThanOrEqual(25);
});

test('snooze action accepts a custom datetime', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    $until = now()->addDays(3)->startOfMinute();

    Livewire::test(ListWebsites::class)
        ->callTableAction('snooze', $website, data: [
            'duration' => 'custom',
            'until' => $until->toDateTimeString(),
        ])
        ->assertHasNoTableActionErrors();

    $website->refresh();

    expect($website->silenced_until?->equalTo($until))->toBeTrue();
});

test('bulk snooze paused notifications for selected websites', function () {
    $user = $this->actingAsSuperAdmin();
    $websites = Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    Livewire::test(ListWebsites::class)
        ->callTableBulkAction('snooze', $websites, data: ['duration' => '4h']);

    foreach ($websites as $website) {
        $website->refresh();
        expect($website->silenced_until)->not->toBeNull()
            ->and($website->silenced_until->isFuture())->toBeTrue();
    }
});

test('bulk unsnooze clears silenced_until on selected websites', function () {
    $user = $this->actingAsSuperAdmin();
    $websites = Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'silenced_until' => now()->addHour(),
    ]);

    Livewire::test(ListWebsites::class)
        ->callTableBulkAction('unsnooze', $websites);

    foreach ($websites as $website) {
        expect($website->refresh()->silenced_until)->toBeNull();
    }
});

test('user without Update:Website permission cannot see the snooze actions', function () {
    $user = \App\Models\User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo('ViewAny:Website');
    $this->actingAs($user);

    Website::factory()->create([
        'created_by' => $user->id,
    ]);

    Livewire::test(ListWebsites::class)
        ->assertTableActionHidden('snooze')
        ->assertTableBulkActionHidden('snooze')
        ->assertTableBulkActionHidden('unsnooze');
});

test('super admin can snooze an api monitor for 1 hour via the row action', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableAction('snooze', $monitor, data: [
            'duration' => '1h',
        ])
        ->assertHasNoTableActionErrors();

    $monitor->refresh();

    expect($monitor->silenced_until)->not->toBeNull()
        ->and($monitor->silenced_until->isFuture())->toBeTrue();
});

test('bulk unsnooze clears silenced_until on selected api monitors', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitors = MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'silenced_until' => now()->addHour(),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('unsnooze', $monitors);

    foreach ($monitors as $monitor) {
        expect($monitor->refresh()->silenced_until)->toBeNull();
    }
});

test('bulk snooze paused notifications for selected api monitors', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitors = MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('snooze', $monitors, data: ['duration' => '4h']);

    foreach ($monitors as $monitor) {
        $monitor->refresh();
        expect($monitor->silenced_until)->not->toBeNull()
            ->and($monitor->silenced_until->isFuture())->toBeTrue();
    }
});

test('snooze action rejects a custom datetime in the past', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    Livewire::test(ListWebsites::class)
        ->callTableAction('snooze', $website, data: [
            'duration' => 'custom',
            'until' => now()->subHour()->toDateTimeString(),
        ]);

    expect($website->refresh()->silenced_until)->toBeNull();
});
