<?php

use App\Filament\Resources\MonitorApisResource\Pages\CreateMonitorApis;
use App\Filament\Resources\MonitorApisResource\Pages\EditMonitorApis;
use App\Filament\Resources\MonitorApisResource\Pages\ListMonitorApis;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Livewire\Livewire;

test('super admin can create api monitor with execution settings', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Checkout API',
            'url' => 'https://example.com/health',
            'http_method' => 'POST',
            'expected_status' => 204,
            'timeout_seconds' => 45,
            'is_enabled' => false,
            'data_path' => 'data.status',
            'headers' => [
                'Authorization' => 'Bearer secret',
            ],
            'save_failed_response' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $monitor = MonitorApis::query()->where('title', 'Checkout API')->firstOrFail();

    expect($monitor->created_by)->toBe($user->id)
        ->and($monitor->http_method)->toBe('POST')
        ->and($monitor->expected_status)->toBe(204)
        ->and($monitor->timeout_seconds)->toBe(45)
        ->and($monitor->is_enabled)->toBeFalse()
        ->and($monitor->data_path)->toBe('data.status')
        ->and($monitor->headers)->toBe(['Authorization' => 'Bearer secret'])
        ->and($monitor->save_failed_response)->toBeFalse();
});

test('super admin can update api monitor execution settings', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'http_method' => 'GET',
        'expected_status' => 200,
        'timeout_seconds' => null,
        'is_enabled' => true,
        'save_failed_response' => true,
    ]);

    Livewire::test(EditMonitorApis::class, ['record' => $monitor->id])
        ->fillForm([
            'http_method' => 'PATCH',
            'expected_status' => 202,
            'timeout_seconds' => 30,
            'is_enabled' => false,
            'save_failed_response' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $monitor->refresh();

    expect($monitor->http_method)->toBe('PATCH')
        ->and($monitor->expected_status)->toBe(202)
        ->and($monitor->timeout_seconds)->toBe(30)
        ->and($monitor->is_enabled)->toBeFalse()
        ->and($monitor->save_failed_response)->toBeFalse();
});

test('super admin can filter to archived api monitors and keep their history visible', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $activeMonitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
    ]);

    $archivedMonitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'source' => 'package',
        'package_name' => 'archived-monitor',
    ]);

    MonitorApiResult::factory()->count(2)->create([
        'monitor_api_id' => $archivedMonitor->id,
    ]);

    $archivedMonitor->delete();

    Livewire::test(ListMonitorApis::class)
        ->filterTable('trashed', 'only')
        ->assertCanSeeTableRecords([$archivedMonitor]);

    expect($archivedMonitor->results()->count())->toBe(2);
});

test('api monitor list shows enabled state', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $enabledMonitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Enabled API',
        'is_enabled' => true,
    ]);

    $disabledMonitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Disabled API',
        'is_enabled' => false,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$enabledMonitor, $disabledMonitor]);
});
