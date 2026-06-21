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

test('server rule table renders skipped stale reporter evidence', function () {
    $user = $this->actingAsSuperAdmin();
    $server = Server::factory()->create(['created_by' => $user->id]);
    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'last_evaluation_status' => 'skipped_stale_reporter',
        'last_evaluation_reason' => 'Latest reporter data is stale; waiting for a fresh sample before evaluating this rule.',
        'last_reported_at' => now()->subMinutes(7),
        'last_evaluated_at' => now(),
    ]);

    Livewire::test(RulesRelationManager::class, [
        'ownerRecord' => $server,
        'pageClass' => EditServer::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$rule])
        ->assertSee('Reporter stale')
        ->assertSee('Latest reporter data is stale; waiting for a fresh sample before evaluating this rule.')
        ->assertSee('Not evaluated');
});

test('server rule table keeps triggered badge when active alert has stale reporter evidence', function () {
    $user = $this->actingAsSuperAdmin();
    $server = Server::factory()->create(['created_by' => $user->id]);
    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'is_triggered' => true,
        'triggered_at' => now()->subMinutes(10),
        'last_evaluation_status' => 'skipped_stale_reporter',
        'last_evaluation_reason' => 'Latest reporter data is stale; waiting for a fresh sample before evaluating this rule.',
        'last_reported_at' => now()->subMinutes(7),
        'last_evaluated_at' => now(),
    ]);

    Livewire::test(RulesRelationManager::class, [
        'ownerRecord' => $server,
        'pageClass' => EditServer::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$rule])
        ->assertSee('Triggered')
        ->assertSee('Reporter data is stale; alert remains triggered until a fresh sample confirms recovery.')
        ->assertSee('Not evaluated');
});

test('server rule table keeps triggered badge when active alert has missing reporter evidence', function () {
    $user = $this->actingAsSuperAdmin();
    $server = Server::factory()->create(['created_by' => $user->id]);
    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'is_triggered' => true,
        'triggered_at' => now()->subMinutes(10),
        'last_evaluation_status' => 'skipped_missing_reporter',
        'last_evaluation_reason' => 'No reporter data has been received for this server.',
        'last_evaluated_at' => now(),
    ]);

    Livewire::test(RulesRelationManager::class, [
        'ownerRecord' => $server,
        'pageClass' => EditServer::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$rule])
        ->assertSee('Triggered')
        ->assertSee('Reporter data is missing; alert remains triggered until fresh data confirms recovery.')
        ->assertSee('Not evaluated');
});

test('server rule table keeps triggered badge when active alert has unreadable metric evidence', function () {
    $user = $this->actingAsSuperAdmin();
    $server = Server::factory()->create(['created_by' => $user->id]);
    $rule = ServerRule::factory()->create([
        'server_id' => $server->id,
        'metric' => 'swap_usage',
        'value' => 90,
        'is_triggered' => true,
        'triggered_at' => now()->subMinutes(10),
        'last_evaluation_status' => 'skipped_unreadable_metric',
        'last_evaluation_reason' => 'Latest reporter data does not include a readable swap_usage sample for this rule.',
        'last_reported_at' => now()->subMinutes(2),
        'last_evaluated_at' => now(),
    ]);

    Livewire::test(RulesRelationManager::class, [
        'ownerRecord' => $server,
        'pageClass' => EditServer::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$rule])
        ->assertSee('Triggered')
        ->assertSee('Reporter data is unreadable; alert remains triggered until a readable sample confirms recovery.')
        ->assertSee('Not evaluated');
});

test('server rule table renders missing reporter evidence', function () {
    $user = $this->actingAsSuperAdmin();
    $server = Server::factory()->create(['created_by' => $user->id]);
    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'last_evaluation_status' => 'skipped_missing_reporter',
        'last_evaluation_reason' => 'No reporter data has been received for this server.',
        'last_evaluated_at' => now(),
    ]);

    Livewire::test(RulesRelationManager::class, [
        'ownerRecord' => $server,
        'pageClass' => EditServer::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$rule])
        ->assertSee('Awaiting data')
        ->assertSee('No reporter data has been received for this server.')
        ->assertSee('Not evaluated');
});

test('server rule table renders unreadable metric evidence', function () {
    $user = $this->actingAsSuperAdmin();
    $server = Server::factory()->create(['created_by' => $user->id]);
    $rule = ServerRule::factory()->create([
        'server_id' => $server->id,
        'metric' => 'swap_usage',
        'value' => 90,
        'last_evaluation_status' => 'skipped_unreadable_metric',
        'last_evaluation_reason' => 'Latest reporter data does not include a readable swap_usage sample for this rule.',
        'last_reported_at' => now()->subMinutes(2),
        'last_evaluated_at' => now(),
    ]);

    Livewire::test(RulesRelationManager::class, [
        'ownerRecord' => $server,
        'pageClass' => EditServer::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$rule])
        ->assertSee('Metric unreadable')
        ->assertSee('Latest reporter data does not include a readable swap_usage sample for this rule.')
        ->assertSee('Not evaluated');
});
