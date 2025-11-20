<?php

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\User;

test('monitor api belongs to user', function () {
    $user = User::factory()->create();
    $monitor = MonitorApis::factory()->create(['created_by' => $user->id]);

    expect($monitor->user)->toBeInstanceOf(User::class);
    expect($monitor->user->id)->toBe($user->id);
});

test('monitor api has many assertions', function () {
    $monitor = MonitorApis::factory()->create();
    MonitorApiAssertion::factory()->count(3)->create(['monitor_api_id' => $monitor->id]);

    expect($monitor->assertions)->toHaveCount(3);
    expect($monitor->assertions->first())->toBeInstanceOf(MonitorApiAssertion::class);
});

test('monitor api assertions are ordered by sort order', function () {
    $monitor = MonitorApis::factory()->create();

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'sort_order' => 3,
    ]);
    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'sort_order' => 1,
    ]);
    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'sort_order' => 2,
    ]);

    $sortOrders = $monitor->fresh()->assertions->pluck('sort_order')->toArray();

    expect($sortOrders)->toBe([1, 2, 3]);
});

test('monitor api has many results', function () {
    $monitor = MonitorApis::factory()->create();
    MonitorApiResult::factory()->count(10)->create(['monitor_api_id' => $monitor->id]);

    expect($monitor->results)->toHaveCount(10);
    expect($monitor->results->first())->toBeInstanceOf(MonitorApiResult::class);
});

test('monitor api requires title', function () {
    MonitorApis::factory()->create(['title' => null]);
})->throws(\Illuminate\Database\QueryException::class);

test('monitor api requires url', function () {
    MonitorApis::factory()->create(['url' => null]);
})->throws(\Illuminate\Database\QueryException::class);

test('monitor api stores headers as json', function () {
    $headers = [
        'Authorization' => 'Bearer token123',
        'Accept' => 'application/json',
    ];

    $monitor = MonitorApis::factory()->create([
        'headers' => json_encode($headers),
    ]);

    expect(json_decode($monitor->headers, true))->toBe($headers);
});

test('monitor api has data path for response extraction', function () {
    $monitor = MonitorApis::factory()->create([
        'data_path' => 'data.results.items',
    ]);

    expect($monitor->data_path)->toBe('data.results.items');
});
