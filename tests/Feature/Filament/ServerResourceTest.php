<?php

use App\Filament\Resources\ServerResource\Pages\ListServers;
use App\Models\Server;
use App\Models\ServerInformationHistory;
use Livewire\Livewire;

test('server list sorts cpu usage by normalized load percentage', function () {
    $user = $this->actingAsSuperAdmin();

    $lowerUsageServer = Server::factory()->create([
        'created_by' => $user->id,
        'cpu_cores' => 8,
    ]);
    $higherUsageServer = Server::factory()->create([
        'created_by' => $user->id,
        'cpu_cores' => 2,
    ]);

    ServerInformationHistory::factory()->create([
        'server_id' => $lowerUsageServer->id,
        'cpu_load' => 3.0, // 37.5% usage
        'created_at' => now(),
    ]);
    ServerInformationHistory::factory()->create([
        'server_id' => $higherUsageServer->id,
        'cpu_load' => 2.0, // 100% usage
        'created_at' => now(),
    ]);

    Livewire::test(ListServers::class)
        ->sortTable('cpu_usage', 'asc')
        ->assertCanSeeTableRecords([$lowerUsageServer, $higherUsageServer], inOrder: true);
});
