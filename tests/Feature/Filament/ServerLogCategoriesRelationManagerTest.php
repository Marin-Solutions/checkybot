<?php

use App\Filament\Resources\ServerResource\Pages\EditServer;
use App\Filament\Resources\ServerResource\RelationManagers\LogCategoriesRelationManager;
use App\Models\Server;
use App\Models\ServerLogCategory;
use App\Models\ServerLogFileHistory;
use Livewire\Livewire;

test('server log category table surfaces collected file history and download actions', function () {
    $user = $this->actingAsSuperAdmin();
    $server = Server::factory()->create(['created_by' => $user->id]);
    $category = ServerLogCategory::factory()->create([
        'server_id' => $server->id,
        'name' => 'Nginx',
        'last_collected_at' => now()->subMinutes(10),
    ]);
    $latestFile = ServerLogFileHistory::factory()->create([
        'server_log_category_id' => $category->id,
        'log_file_name' => 'ServerLogFiles/latest-nginx.log',
        'created_at' => now(),
    ]);
    ServerLogFileHistory::factory()->create([
        'server_log_category_id' => $category->id,
        'log_file_name' => 'ServerLogFiles/previous-nginx.log',
        'created_at' => now()->subHour(),
    ]);

    Livewire::test(LogCategoriesRelationManager::class, [
        'ownerRecord' => $server,
        'pageClass' => EditServer::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$category])
        ->assertSee('Collected Files')
        ->assertSee('Last Collected')
        ->assertSee('latest-nginx.log')
        ->assertTableActionVisible('viewLogFiles', $category)
        ->assertTableActionVisible('downloadLatestLogFile', $category)
        ->assertTableActionHasUrl('downloadLatestLogFile', route('server-log-file-history.download', $latestFile), $category);
});
