<?php

use App\Filament\Resources\MonitorApisResource\Pages\ListMonitorApis;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Livewire\Livewire;

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
