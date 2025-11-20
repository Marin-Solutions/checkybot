<?php

use App\Models\Server;
use App\Models\ServerInformationHistory;
use App\Models\ServerLogCategory;
use App\Models\ServerRule;
use App\Models\User;

test('server belongs to user', function () {
    $user = User::factory()->create();
    $server = Server::factory()->create(['created_by' => $user->id]);

    expect($server->user)->toBeInstanceOf(User::class);
    expect($server->user->id)->toBe($user->id);
});

test('server has many information history', function () {
    $server = Server::factory()->create();
    ServerInformationHistory::factory()->count(5)->create(['server_id' => $server->id]);

    expect($server->informationHistory)->toHaveCount(5);
    expect($server->informationHistory->first())->toBeInstanceOf(ServerInformationHistory::class);
});

test('server has many rules', function () {
    $server = Server::factory()->create();
    ServerRule::factory()->count(3)->create(['server_id' => $server->id]);

    expect($server->rules)->toHaveCount(3);
    expect($server->rules->first())->toBeInstanceOf(ServerRule::class);
});

test('server has many log categories', function () {
    $server = Server::factory()->create();
    ServerLogCategory::factory()->count(2)->create(['server_id' => $server->id]);

    expect($server->logCategories)->toHaveCount(2);
});

test('server requires name', function () {
    Server::factory()->create(['name' => null]);
})->throws(\Illuminate\Database\QueryException::class);

test('server requires ip', function () {
    Server::factory()->create(['ip' => null]);
})->throws(\Illuminate\Database\QueryException::class);

test('server can have ploi server id', function () {
    $server = Server::factory()->create(['ploi_server_id' => 12345]);

    expect($server->ploi_server_id)->toBe(12345);
});

test('server has token for authentication', function () {
    $server = Server::factory()->create();

    expect($server->token)->not->toBeNull();
    expect($server->token)->toBeString();
});

test('server tracks cpu cores', function () {
    $server = Server::factory()->create(['cpu_cores' => 8]);

    expect($server->cpu_cores)->toBe(8);
});
