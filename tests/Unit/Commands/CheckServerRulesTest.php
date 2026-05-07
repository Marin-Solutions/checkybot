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

test('command records skipped evidence when server reporter data is missing', function () {
    $server = Server::factory()->create();
    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
    ]);

    $this->artisan('server:check-rules')
        ->expectsOutput("Skipped server rule for {$server->name}: no reporter data has been received")
        ->assertSuccessful();

    $rule->refresh();

    expect($rule->is_triggered)->toBeFalse();
    expect($rule->last_evaluated_value)->toBeNull();
    expect($rule->last_evaluated_at)->not->toBeNull();
    expect($rule->last_evaluation_status)->toBe('skipped_missing_reporter');
    expect($rule->last_evaluation_reason)->toBe('No reporter data has been received for this server.');
    expect($rule->last_reported_at)->toBeNull();
});

test('command skips stale server reporter data without triggering alerts', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $server = Server::factory()->create();
    $channel = NotificationChannels::factory()->create([
        'created_by' => $server->created_by,
        'url' => 'https://example.com/server-rule-webhook',
    ]);
    $reportedAt = now()->subMinutes(6);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 5,
        'created_at' => $reportedAt,
    ]);

    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'channel' => (string) $channel->id,
    ]);

    $this->artisan('server:check-rules')
        ->expectsOutput("Skipped server rule for {$server->name}: latest reporter data is stale from {$reportedAt->toDateTimeString()}")
        ->assertSuccessful();

    Http::assertNothingSent();

    $rule->refresh();

    expect($rule->is_triggered)->toBeFalse();
    expect($rule->triggered_at)->toBeNull();
    expect($rule->last_evaluated_value)->toBeNull();
    expect($rule->last_evaluated_at)->not->toBeNull();
    expect($rule->last_evaluation_status)->toBe('skipped_stale_reporter');
    expect($rule->last_evaluation_reason)->toBe('Latest reporter data is stale; waiting for a fresh sample before evaluating this rule.');
    expect($rule->last_reported_at->toDateTimeString())->toBe($reportedAt->toDateTimeString());
});

test('command skips stale server reporter data without recovering triggered alerts', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $server = Server::factory()->create();
    $reportedAt = now()->subMinutes(6);
    $triggeredAt = now()->subMinutes(10);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 20,
        'created_at' => $reportedAt,
    ]);

    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'is_triggered' => true,
        'triggered_at' => $triggeredAt,
    ]);

    $this->artisan('server:check-rules')
        ->assertSuccessful();

    Http::assertNothingSent();

    $rule->refresh();

    expect($rule->is_triggered)->toBeTrue();
    expect($rule->triggered_at->toDateTimeString())->toBe($triggeredAt->toDateTimeString());
    expect($rule->recovered_at)->toBeNull();
    expect($rule->last_evaluated_value)->toBeNull();
    expect($rule->last_evaluation_status)->toBe('skipped_stale_reporter');
    expect($rule->last_reported_at->toDateTimeString())->toBe($reportedAt->toDateTimeString());
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
        ->expectsOutput("Rule condition met for server {$server->name}: cpu_usage = 87.5")
        ->assertSuccessful();
});

test('command compares cpu usage thresholds against normalized cpu load', function () {
    $server = Server::factory()->create(['cpu_cores' => 4]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'cpu_load' => 2.8, // 70% usage
    ]);

    ServerRule::factory()->cpuUsage()->create([
        'server_id' => $server->id,
        'value' => 80,
    ]);

    $this->artisan('server:check-rules')
        ->doesntExpectOutput("Rule condition met for server {$server->name}: cpu_usage = 2.8")
        ->doesntExpectOutput("Rule condition met for server {$server->name}: cpu_usage = 70")
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
        'created_by' => $server->created_by,
        'url' => 'https://example.com/server-rule-webhook',
    ]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 5,
    ]);

    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'channel' => (string) $channel->id,
    ]);

    $this->artisan('server:check-rules')
        ->expectsOutput("Rule condition met for server {$server->name}: ram_usage = 95")
        ->expectsOutput("Webhook notification failed for server {$server->name} with response code 401")
        ->assertSuccessful();

    Http::assertSentCount(1);

    $rule->refresh();

    expect($rule->is_triggered)->toBeFalse();
    expect($rule->triggered_at)->toBeNull();
    expect($rule->last_evaluated_value)->toBe(95.0);
    expect($rule->last_evaluated_at)->not->toBeNull();
});

test('command sends server rule notification only when threshold transitions to triggered', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $server = Server::factory()->create();
    $channel = NotificationChannels::factory()->create([
        'created_by' => $server->created_by,
        'url' => 'https://example.com/server-rule-webhook',
    ]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 5,
    ]);

    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'channel' => (string) $channel->id,
    ]);

    $this->artisan('server:check-rules')
        ->expectsOutput("Rule condition met for server {$server->name}: ram_usage = 95")
        ->expectsOutput("Notification sent for server {$server->name}")
        ->assertSuccessful();

    $this->artisan('server:check-rules')
        ->assertSuccessful();

    Http::assertSentCount(1);

    $rule->refresh();

    expect($rule->is_triggered)->toBeTrue();
    expect($rule->triggered_at)->not->toBeNull();
    expect($rule->recovered_at)->toBeNull();
    expect($rule->last_evaluated_value)->toBe(95.0);
    expect($rule->last_evaluated_at)->not->toBeNull();
});

test('command resets server rule state when threshold recovers', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $server = Server::factory()->create();
    $channel = NotificationChannels::factory()->create([
        'created_by' => $server->created_by,
        'url' => 'https://example.com/server-rule-webhook',
    ]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 20,
    ]);

    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'channel' => (string) $channel->id,
        'is_triggered' => true,
        'triggered_at' => now()->subMinutes(5),
    ]);

    $this->artisan('server:check-rules')
        ->expectsOutput("Rule recovered for server {$server->name}: ram_usage = 80")
        ->assertSuccessful();

    Http::assertSentCount(0);

    $rule->refresh();

    expect($rule->is_triggered)->toBeFalse();
    expect($rule->triggered_at)->not->toBeNull();
    expect($rule->recovered_at)->not->toBeNull();
    expect($rule->last_evaluated_value)->toBe(80.0);
    expect($rule->last_evaluated_at)->not->toBeNull();
});

test('command sends server rule notification again after recovery and new breach', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $server = Server::factory()->create();
    $channel = NotificationChannels::factory()->create([
        'created_by' => $server->created_by,
        'url' => 'https://example.com/server-rule-webhook',
    ]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 5,
        'created_at' => now()->subMinutes(2),
    ]);

    ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'channel' => (string) $channel->id,
    ]);

    $this->artisan('server:check-rules')
        ->assertSuccessful();

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 20,
        'created_at' => now()->subMinute(),
    ]);

    $this->artisan('server:check-rules')
        ->assertSuccessful();

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 5,
        'created_at' => now(),
    ]);

    $this->artisan('server:check-rules')
        ->assertSuccessful();

    Http::assertSentCount(2);
});

test('command sends server rule notification when re-enabled rule is still breached', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $server = Server::factory()->create();
    $channel = NotificationChannels::factory()->create([
        'created_by' => $server->created_by,
        'url' => 'https://example.com/server-rule-webhook',
    ]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 5,
    ]);

    $rule = ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'channel' => (string) $channel->id,
        'is_triggered' => true,
        'triggered_at' => now()->subMinutes(5),
    ]);

    $rule->update(['is_active' => false]);
    $rule->update(['is_active' => true]);

    $this->artisan('server:check-rules')
        ->expectsOutput("Rule condition met for server {$server->name}: ram_usage = 95")
        ->expectsOutput("Notification sent for server {$server->name}")
        ->assertSuccessful();

    Http::assertSentCount(1);

    $rule->refresh();

    expect($rule->is_triggered)->toBeTrue();
    expect($rule->triggered_at)->not->toBeNull();
});

test('command does not send server rule notifications to another users webhook channel', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $server = Server::factory()->create();
    $otherChannel = NotificationChannels::factory()->create([
        'url' => 'https://example.com/other-user-webhook',
    ]);

    ServerInformationHistory::factory()->create([
        'server_id' => $server->id,
        'ram_free_percentage' => 5,
    ]);

    ServerRule::factory()->ramUsage()->create([
        'server_id' => $server->id,
        'value' => 90,
        'channel' => (string) $otherChannel->id,
    ]);

    $this->artisan('server:check-rules')
        ->expectsOutput("Rule condition met for server {$server->name}: ram_usage = 95")
        ->expectsOutput("Notification channel not found for rule on server {$server->name}")
        ->assertSuccessful();

    Http::assertNothingSent();
});
