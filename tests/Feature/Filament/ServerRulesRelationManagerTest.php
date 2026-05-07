<?php

use App\Filament\Resources\ServerResource\Pages\EditServer;
use App\Filament\Resources\ServerResource\RelationManagers\RulesRelationManager;
use App\Models\NotificationChannels;
use App\Models\Server;
use App\Models\ServerRule;
use Livewire\Livewire;

test('server rule table does not render another users webhook channel title', function () {
    $user = $this->actingAsSuperAdmin();
    $server = Server::factory()->create(['created_by' => $user->id]);
    $otherChannel = NotificationChannels::factory()->create([
        'title' => 'External Secret Hook',
    ]);
    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'channel' => (string) $otherChannel->id,
    ]);

    Livewire::test(RulesRelationManager::class, [
        'ownerRecord' => $server,
        'pageClass' => EditServer::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$rule])
        ->assertDontSee('External Secret Hook')
        ->assertSee((string) $otherChannel->id);
});

test('server rule table renders trigger evidence', function () {
    $user = $this->actingAsSuperAdmin();
    $server = Server::factory()->create(['created_by' => $user->id]);
    $channel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'title' => 'Ops Webhook',
    ]);
    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'channel' => (string) $channel->id,
        'value' => 90,
        'is_triggered' => true,
        'triggered_at' => now()->subMinutes(5),
        'last_evaluated_value' => 95,
        'last_evaluated_at' => now()->subMinute(),
    ]);

    Livewire::test(RulesRelationManager::class, [
        'ownerRecord' => $server,
        'pageClass' => EditServer::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$rule])
        ->assertSee('Triggered')
        ->assertSee('Last checked 95% &gt; 90%', false)
        ->assertSee('95%')
        ->assertSee('Not recovered');
});
