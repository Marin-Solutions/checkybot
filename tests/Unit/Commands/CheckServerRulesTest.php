<?php

use App\Models\NotificationChannels;
use App\Models\Server;
use App\Models\ServerInformationHistory;
use App\Models\ServerRule;
use Illuminate\Support\Facades\Http;

test('command checks all active server rules', function () {
    $server = Server::factory()->create(['cpu_cores' => 4]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'cpu_load' => 3.0, // 75% usage (3.0 / 4 cores)
        'ram_free_percentage' => 20, // 80% used
        'disk_free_percentage' => 15, // 85% used
    ]);

    ServerRule::factory()->create([
        'server_id' => $server->id,
        'metric' => 'cpu_usage',
        'operator' => '>',
        'value' => 70,
        'is_active' => true,
    ]);

    $this->artisan('server:check-rules')
        ->assertSuccessful();
});

test('command skips inactive rules', function () {
    $server = Server::factory()->create();

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'cpu_load' => 3.8,
    ]);

    ServerRule::factory()->create([
        'server_id' => $server->id,
        'metric' => 'cpu_usage',
        'operator' => '>',
        'value' => 70,
        'is_active' => false,
    ]);

    $this->artisan('server:check-rules')
        ->assertSuccessful();
});

test('command evaluates cpu usage rule', function () {
    $server = Server::factory()->create(['cpu_cores' => 4]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'cpu_load' => 3.5, // 87.5% usage
    ]);

    ServerRule::factory()->cpuUsage()->create([
        'server_id' => $server->id,
        'value' => 80,
    ]);

    $this->artisan('server:check-rules')
        ->assertSuccessful();
});

test('command evaluates ram usage rule', function () {
    $server = Server::factory()->create();

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 5, // 95% used
    ]);

    ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
    ]);

    $this->artisan('server:check-rules')
        ->assertSuccessful();
});

test('command evaluates disk usage rule', function () {
    $server = Server::factory()->create();

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'disk_free_percentage' => 10, // 90% used
    ]);

    ServerRule::factory()->diskUsage()->create([
        'server_id' => $server->id,
        'value' => 85,
    ]);

    $this->artisan('server:check-rules')
        ->assertSuccessful();
});

test('command reports webhook notification failure when server rule destination returns non-2xx', function () {
    Http::fake([
        '*' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $server = Server::factory()->create();
    $channel = NotificationChannels::factory()->create([
        'url' => 'https://example.com/server-rule-webhook',
    ]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 5,
    ]);

    ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'channel' => (string) $channel->id,
    ]);

    $this->artisan('server:check-rules')
        ->expectsOutput("Rule condition met for server {$server->name}: ram_usage = 95")
        ->expectsOutput("Webhook notification failed for server {$server->name} with response code 401")
        ->assertSuccessful();

    Http::assertSentCount(1);
});
