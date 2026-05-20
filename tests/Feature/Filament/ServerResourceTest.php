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

test('server list marks metric bars stale when latest reporter history is old', function () {
    $user = $this->actingAsSuperAdmin();

    $server = Server::factory()->create([
        'created_by' => $user->id,
        'cpu_cores' => 2,
    ]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'cpu_load' => 1.8,
        'ram_free_percentage' => 10,
        'disk_free_percentage' => 10,
        'created_at' => now()->subMinutes(3),
    ]);

    Livewire::test(ListServers::class)
        ->assertCanSeeTableRecords([$server])
        ->assertSee('Offline')
        ->assertSee('Stale')
        ->assertSeeHtml('data-stale-server-metric')
        ->assertDontSee('90%');
});

test('server list shows current metric bars when latest reporter history is fresh', function () {
    $user = $this->actingAsSuperAdmin();

    $server = Server::factory()->create([
        'created_by' => $user->id,
        'cpu_cores' => 2,
    ]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'cpu_load' => 1.8,
        'ram_free_percentage' => 10,
        'disk_free_percentage' => 10,
        'created_at' => now(),
    ]);

    Livewire::test(ListServers::class)
        ->assertCanSeeTableRecords([$server])
        ->assertSee('Online')
        ->assertSee('90%')
        ->assertDontSeeHtml('data-stale-server-metric');
});

test('server list metric labels omit decimal places', function () {
    $user = $this->actingAsSuperAdmin();

    $server = Server::factory()->create([
        'created_by' => $user->id,
        'cpu_cores' => 8,
    ]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'cpu_load' => 3.1175, // 38.96875% usage
        'ram_free_percentage' => 59.1, // 40.9% usage
        'disk_free_percentage' => 65,
        'created_at' => now(),
    ]);

    Livewire::test(ListServers::class)
        ->assertCanSeeTableRecords([$server])
        ->assertSeeHtml('<span class="fi-ta-progress-label">38%</span>')
        ->assertSeeHtml('<span class="fi-ta-progress-label">40%</span>')
        ->assertDontSeeHtml('<span class="fi-ta-progress-label">38.96875%</span>')
        ->assertDontSeeHtml('<span class="fi-ta-progress-label">40.9%</span>');
});

test('server list filters offline reporters', function () {
    $user = $this->actingAsSuperAdmin();

    $freshServer = Server::factory()->create(['created_by' => $user->id]);
    $staleServer = Server::factory()->create(['created_by' => $user->id]);
    $neverReportedServer = Server::factory()->create(['created_by' => $user->id]);

    ServerInformationHistory::factory()->create([
        'server_id' => $freshServer->id,
        'created_at' => now(),
    ]);
    ServerInformationHistory::factory()->create([
        'server_id' => $staleServer->id,
        'created_at' => now()->subMinutes(3),
    ]);

    Livewire::test(ListServers::class)
        ->filterTable('server_attention', 'offline_reporters')
        ->assertCanSeeTableRecords([$staleServer, $neverReportedServer])
        ->assertCanNotSeeTableRecords([$freshServer]);
});

test('server list filters stale metrics without showing servers that never reported', function () {
    $user = $this->actingAsSuperAdmin();

    $freshServer = Server::factory()->create(['created_by' => $user->id]);
    $staleServer = Server::factory()->create(['created_by' => $user->id]);
    $neverReportedServer = Server::factory()->create(['created_by' => $user->id]);

    ServerInformationHistory::factory()->create([
        'server_id' => $freshServer->id,
        'created_at' => now(),
    ]);
    ServerInformationHistory::factory()->create([
        'server_id' => $staleServer->id,
        'created_at' => now()->subMinutes(3),
    ]);

    Livewire::test(ListServers::class)
        ->filterTable('server_attention', 'stale_metrics')
        ->assertCanSeeTableRecords([$staleServer])
        ->assertCanNotSeeTableRecords([$freshServer, $neverReportedServer]);
});

test('server list filters warning usage', function () {
    $user = $this->actingAsSuperAdmin();

    $healthyServer = Server::factory()->create(['created_by' => $user->id, 'cpu_cores' => 4]);
    $warningServer = Server::factory()->create(['created_by' => $user->id, 'cpu_cores' => 4]);
    $criticalServer = Server::factory()->create(['created_by' => $user->id, 'cpu_cores' => 4]);
    $staleWarningServer = Server::factory()->create(['created_by' => $user->id, 'cpu_cores' => 4]);

    ServerInformationHistory::factory()->create([
        'server_id' => $healthyServer->id,
        'cpu_load' => 1,
        'ram_free_percentage' => 60,
        'disk_free_percentage' => 60,
        'created_at' => now(),
    ]);
    ServerInformationHistory::factory()->create([
        'server_id' => $warningServer->id,
        'cpu_load' => 1,
        'ram_free_percentage' => 25,
        'disk_free_percentage' => 60,
        'created_at' => now(),
    ]);
    ServerInformationHistory::factory()->create([
        'server_id' => $criticalServer->id,
        'cpu_load' => 1,
        'ram_free_percentage' => 5,
        'disk_free_percentage' => 60,
        'created_at' => now(),
    ]);
    ServerInformationHistory::factory()->create([
        'server_id' => $staleWarningServer->id,
        'cpu_load' => 1,
        'ram_free_percentage' => 25,
        'disk_free_percentage' => 60,
        'created_at' => now()->subMinutes(3),
    ]);

    Livewire::test(ListServers::class)
        ->filterTable('server_attention', 'warning_usage')
        ->assertCanSeeTableRecords([$warningServer])
        ->assertCanNotSeeTableRecords([$healthyServer, $criticalServer, $staleWarningServer]);
});

test('server list filters critical usage', function () {
    $user = $this->actingAsSuperAdmin();

    $healthyServer = Server::factory()->create(['created_by' => $user->id, 'cpu_cores' => 4]);
    $warningServer = Server::factory()->create(['created_by' => $user->id, 'cpu_cores' => 4]);
    $criticalServer = Server::factory()->create(['created_by' => $user->id, 'cpu_cores' => 4]);
    $staleCriticalServer = Server::factory()->create(['created_by' => $user->id, 'cpu_cores' => 4]);

    ServerInformationHistory::factory()->create([
        'server_id' => $healthyServer->id,
        'cpu_load' => 1,
        'ram_free_percentage' => 60,
        'disk_free_percentage' => 60,
        'created_at' => now(),
    ]);
    ServerInformationHistory::factory()->create([
        'server_id' => $warningServer->id,
        'cpu_load' => 3,
        'ram_free_percentage' => 60,
        'disk_free_percentage' => 60,
        'created_at' => now(),
    ]);
    ServerInformationHistory::factory()->create([
        'server_id' => $criticalServer->id,
        'cpu_load' => 1,
        'ram_free_percentage' => 60,
        'disk_free_percentage' => 10,
        'created_at' => now(),
    ]);
    ServerInformationHistory::factory()->create([
        'server_id' => $staleCriticalServer->id,
        'cpu_load' => 1,
        'ram_free_percentage' => 60,
        'disk_free_percentage' => 10,
        'created_at' => now()->subMinutes(3),
    ]);

    Livewire::test(ListServers::class)
        ->filterTable('server_attention', 'critical_usage')
        ->assertCanSeeTableRecords([$criticalServer])
        ->assertCanNotSeeTableRecords([$healthyServer, $warningServer, $staleCriticalServer]);
});
