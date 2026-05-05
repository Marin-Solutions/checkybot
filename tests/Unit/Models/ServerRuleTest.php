<?php

use App\Models\Server;
use App\Models\ServerRule;

test('server rule belongs to server', function () {
    $server = Server::factory()->create();
    $rule = ServerRule::factory()->create(['server_id' => $server->id]);

    expect($rule->server)->toBeInstanceOf(Server::class);
    expect($rule->server->id)->toBe($server->id);
});

test('server rule has fillable attributes', function () {
    $rule = ServerRule::factory()->create([
        'metric' => 'cpu_usage',
        'operator' => '>',
        'value' => 80.5,
        'channel' => 'email',
        'is_active' => true,
    ]);

    expect($rule->metric)->toBe('cpu_usage');
    expect($rule->operator)->toBe('>');
    expect($rule->value)->toBe(80.5);
    expect($rule->channel)->toBe('email');
    expect($rule->is_active)->toBeTrue();
});

test('server rule casts value to float', function () {
    $rule = ServerRule::factory()->create(['value' => '85']);

    expect($rule->value)->toBeFloat();
    expect($rule->value)->toBe(85.0);
});

test('server rule casts is active to boolean', function () {
    $rule = ServerRule::factory()->create(['is_active' => 1]);

    expect($rule->is_active)->toBeBool();
    expect($rule->is_active)->toBeTrue();
});

test('server rule casts transition state', function () {
    $rule = ServerRule::factory()->create([
        'is_triggered' => 1,
        'triggered_at' => now(),
        'recovered_at' => now(),
    ]);

    expect($rule->is_triggered)->toBeBool();
    expect($rule->is_triggered)->toBeTrue();
    expect($rule->triggered_at)->toBeInstanceOf(Illuminate\Support\Carbon::class);
    expect($rule->recovered_at)->toBeInstanceOf(Illuminate\Support\Carbon::class);
});

test('server rule can be inactive', function () {
    $rule = ServerRule::factory()->create(['is_active' => false]);

    expect($rule->is_active)->toBeFalse();
});

test('server rule clears transition state when deactivated', function () {
    $rule = ServerRule::factory()->create([
        'is_triggered' => true,
        'triggered_at' => now()->subMinutes(5),
        'recovered_at' => now()->subMinute(),
    ]);

    $rule->update(['is_active' => false]);
    $rule->refresh();

    expect($rule->is_active)->toBeFalse();
    expect($rule->is_triggered)->toBeFalse();
    expect($rule->triggered_at)->toBeNull();
    expect($rule->recovered_at)->toBeNull();
});

test('server rule supports cpu usage metric', function () {
    $rule = ServerRule::factory()->cpuUsage()->create();

    expect($rule->metric)->toBe('cpu_usage');
    expect($rule->operator)->toBe('>');
    expect($rule->value)->toBe(80.0);
});

test('server rule supports ram usage metric', function () {
    $rule = ServerRule::factory()->ramUsage()->create();

    expect($rule->metric)->toBe('ram_usage');
    expect($rule->operator)->toBe('>');
    expect($rule->value)->toBe(90.0);
});

test('server rule supports disk usage metric', function () {
    $rule = ServerRule::factory()->diskUsage()->create();

    expect($rule->metric)->toBe('disk_usage');
    expect($rule->operator)->toBe('>');
    expect($rule->value)->toBe(85.0);
});

test('server rule supports different operators', function () {
    $greaterThanRule = ServerRule::factory()->create(['operator' => '>']);
    $lessThanRule = ServerRule::factory()->create(['operator' => '<']);
    $equalsRule = ServerRule::factory()->create(['operator' => '=']);

    expect($greaterThanRule->operator)->toBe('>');
    expect($lessThanRule->operator)->toBe('<');
    expect($equalsRule->operator)->toBe('=');
});

test('server rule supports different channels', function () {
    $emailRule = ServerRule::factory()->create(['channel' => 'email']);
    $webhookRule = ServerRule::factory()->create(['channel' => 'webhook']);

    expect($emailRule->channel)->toBe('email');
    expect($webhookRule->channel)->toBe('webhook');
});
