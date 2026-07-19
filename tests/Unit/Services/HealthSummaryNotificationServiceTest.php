<?php

use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use App\Models\Website;
use App\Services\HealthSummaryNotificationService;

test('health summary groups monitor states and highlights checks needing attention', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'created_by' => $user->id,
        'name' => 'Checkout',
    ]);

    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Orders API',
        'current_status' => 'danger',
    ]);
    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Catalog API',
        'current_status' => 'healthy',
    ]);
    MonitorApis::factory()->disabled()->create([
        'created_by' => $user->id,
        'title' => 'Disabled API',
        'current_status' => 'danger',
    ]);
    Website::factory()->create([
        'created_by' => $user->id,
        'name' => 'Storefront',
        'current_status' => 'warning',
    ]);
    ProjectComponent::factory()->create([
        'created_by' => $user->id,
        'project_id' => $project->id,
        'name' => 'queue',
        'source' => 'proxy_pool',
        'current_status' => 'healthy',
    ]);

    $payload = app(HealthSummaryNotificationService::class)->payloadForUser($user->id);

    expect($payload['message'])->toBe('📊 Checkybot summary — 🔴 1 danger · 🟡 1 warning')
        ->and($payload['description'])->toContain('🔌 API checks: ✅ 1 · 🟡 0 · 🔴 1 · ⚪ 0')
        ->and($payload['description'])->toContain('🌐 Websites: ✅ 0 · 🟡 1 · 🔴 0 · ⚪ 0')
        ->and($payload['description'])->toContain('🧩 Components: ✅ 1 · 🟡 0 · 🔴 0 · ⚪ 0')
        ->and($payload['description'])->toContain('🔴 API — Orders API')
        ->and($payload['description'])->toContain('🟡 Website — Storefront')
        ->and($payload['description'])->not->toContain('Disabled API');
});

test('health summary does not describe pending checks as all clear', function () {
    $user = User::factory()->create();

    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'unknown',
    ]);

    $payload = app(HealthSummaryNotificationService::class)->payloadForUser($user->id);

    expect($payload['message'])->toBe('📊 Checkybot summary — ⚪ 1 pending');
});

test('health summary reports all clear when there are no unhealthy checks', function () {
    $user = User::factory()->create();

    Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);

    $payload = app(HealthSummaryNotificationService::class)->payloadForUser($user->id);

    expect($payload['message'])->toBe('📊 Checkybot summary — ✅ all clear')
        ->and($payload['description'])->not->toContain('Needs attention:');
});
