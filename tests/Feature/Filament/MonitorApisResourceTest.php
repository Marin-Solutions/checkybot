<?php

use App\Filament\Resources\MonitorApisResource\Pages\CreateMonitorApis;
use App\Filament\Resources\MonitorApisResource\Pages\EditMonitorApis;
use App\Filament\Resources\MonitorApisResource\Pages\ListMonitorApis;
use App\Filament\Resources\MonitorApisResource\Pages\ViewMonitorApis;
use App\Filament\Resources\MonitorApisResource\RelationManagers\ResultsRelationManager;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('super admin can create api monitor with execution settings', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Checkout API',
            'url' => 'https://example.com/health',
            'http_method' => 'POST',
            'expected_status' => 204,
            'timeout_seconds' => 45,
            'is_enabled' => false,
            'data_path' => 'data.status',
            'headers' => [
                'Authorization' => 'Bearer secret',
            ],
            'save_failed_response' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $monitor = MonitorApis::query()->where('title', 'Checkout API')->firstOrFail();

    expect($monitor->created_by)->toBe($user->id)
        ->and($monitor->http_method)->toBe('POST')
        ->and($monitor->expected_status)->toBe(204)
        ->and($monitor->timeout_seconds)->toBe(45)
        ->and($monitor->is_enabled)->toBeFalse()
        ->and($monitor->data_path)->toBe('data.status')
        ->and($monitor->headers)->toBe(['Authorization' => 'Bearer secret'])
        ->and($monitor->save_failed_response)->toBeFalse();
});

test('super admin can update api monitor execution settings', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'http_method' => 'GET',
        'expected_status' => 200,
        'timeout_seconds' => null,
        'is_enabled' => true,
        'save_failed_response' => true,
    ]);

    Livewire::test(EditMonitorApis::class, ['record' => $monitor->id])
        ->fillForm([
            'http_method' => 'PATCH',
            'expected_status' => 202,
            'timeout_seconds' => 30,
            'is_enabled' => false,
            'save_failed_response' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $monitor->refresh();

    expect($monitor->http_method)->toBe('PATCH')
        ->and($monitor->expected_status)->toBe(202)
        ->and($monitor->timeout_seconds)->toBe(30)
        ->and($monitor->is_enabled)->toBeFalse()
        ->and($monitor->save_failed_response)->toBeFalse();
});

test('super admin can filter to archived api monitors and keep their history visible', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $activeMonitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
    ]);

    $archivedMonitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'source' => 'package',
        'package_name' => 'archived-monitor',
    ]);

    MonitorApiResult::factory()->count(2)->create([
        'monitor_api_id' => $archivedMonitor->id,
    ]);

    $archivedMonitor->delete();

    Livewire::test(ListMonitorApis::class)
        ->filterTable('trashed', 'only')
        ->assertCanSeeTableRecords([$archivedMonitor]);

    expect($archivedMonitor->results()->count())->toBe(2);
});

test('api monitor list shows enabled state', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $enabledMonitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Enabled API',
        'is_enabled' => true,
    ]);

    $disabledMonitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Disabled API',
        'is_enabled' => false,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$enabledMonitor, $disabledMonitor])
        ->assertTableColumnExists('is_enabled');
});

test('list test action uses stored execution settings', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response('', 204),
    ]);

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'POST health',
        'url' => 'https://example.com/health',
        'http_method' => 'POST',
        'expected_status' => 204,
        'timeout_seconds' => 30,
        'data_path' => 'data.status',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableAction('test', $monitor);

    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://example.com/health');
});

test('check api action treats configured non-200 expected status as success', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response('', 204),
    ]);

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Async API',
            'url' => 'https://example.com/async-health',
            'http_method' => 'POST',
            'expected_status' => 204,
            'data_path' => null,
        ])
        ->call('doMonitoring')
        ->assertNotified('API response received');

    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://example.com/async-health');
});

test('check api action treats server errors as danger', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response(['error' => 'server blew up'], 500),
    ]);

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Broken API',
            'url' => 'https://example.com/broken-health',
            'http_method' => 'GET',
            'expected_status' => 200,
            'data_path' => null,
        ])
        ->call('doMonitoring')
        ->assertNotified('API request failed');

    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://example.com/broken-health');
});

test('check api action uses degraded title for expected status mismatches without assertion failures', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response('', 200),
    ]);

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Created API',
            'url' => 'https://example.com/created-health',
            'http_method' => 'POST',
            'expected_status' => 201,
            'data_path' => null,
        ])
        ->call('doMonitoring')
        ->assertNotified('API response is degraded');

    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://example.com/created-health');
});

test('api monitor view shows evidence rich latest run overview', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Orders API',
        'url' => 'https://example.com/orders/health',
        'http_method' => 'POST',
        'expected_status' => 200,
        'data_path' => 'data.status',
        'headers' => [
            'Authorization' => 'Bearer secret-token',
            'X-Env' => 'staging',
        ],
        'current_status' => 'danger',
        'status_summary' => 'API heartbeat failed with HTTP status 500.',
        'save_failed_response' => true,
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'status' => 'danger',
        'summary' => 'API heartbeat failed with HTTP status 500.',
        'http_code' => 500,
        'response_time_ms' => 1450,
        'failed_assertions' => [[
            'path' => '_http_status',
            'type' => 'status_code',
            'message' => 'Expected HTTP status 200, got 500.',
        ]],
        'request_headers' => [
            'Authorization' => '[redacted]',
            'X-Env' => 'staging',
        ],
        'response_headers' => [
            'content-type' => 'application/json',
            'x-request-id' => 'req-123',
        ],
        'response_body' => [
            'error' => 'server blew up',
            'trace_id' => 'trace-123',
        ],
    ]);

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->assertSee('Overview')
        ->assertSee('Latest Run Evidence')
        ->assertSee('API heartbeat failed with HTTP status 500.')
        ->assertSee('Expected HTTP status 200, got 500.')
        ->assertSee('[redacted]')
        ->assertSee('x-request-id')
        ->assertSee('trace-123');
});

test('api monitor results list exposes drill down action with evidence summary', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
    ]);

    $result = MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'status' => 'warning',
        'summary' => 'API heartbeat is degraded with HTTP status 404.',
        'http_code' => 404,
        'failed_assertions' => [[
            'path' => 'data.status',
            'type' => 'value_compare',
            'message' => 'Expected ok, got missing.',
        ]],
        'request_headers' => [
            'Authorization' => '[redacted]',
        ],
        'response_headers' => [
            'content-type' => 'application/json',
        ],
        'response_body' => [
            'raw_body' => '{"status":"missing"}',
        ],
    ]);

    Livewire::test(ResultsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => ViewMonitorApis::class,
    ])
        ->assertTableActionExists('view', null, $result)
        ->assertSee('API heartbeat is degraded with HTTP status 404.')
        ->assertSee('View Evidence')
        ->assertSee('1');
});

test('api monitor navigation badge shows plain total when everything is healthy', function () {
    $user = $this->actingAsSuperAdmin();
    MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);

    expect(\App\Filament\Resources\MonitorApisResource::getNavigationBadge())->toBe('2')
        ->and(\App\Filament\Resources\MonitorApisResource::getNavigationBadgeColor())->toBeNull();
});

test('api monitor navigation badge highlights unhealthy count in danger color', function () {
    $user = $this->actingAsSuperAdmin();
    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'warning',
    ]);
    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    expect(\App\Filament\Resources\MonitorApisResource::getNavigationBadge())->toBe('2/3')
        ->and(\App\Filament\Resources\MonitorApisResource::getNavigationBadgeColor())->toBe('danger');
});

test('api monitor navigation badge is scoped to the current user', function () {
    $user = $this->actingAsSuperAdmin();
    $otherUser = User::factory()->create();

    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    MonitorApis::factory()->create([
        'created_by' => $otherUser->id,
        'current_status' => 'danger',
    ]);

    expect(\App\Filament\Resources\MonitorApisResource::getNavigationBadge())->toBe('1')
        ->and(\App\Filament\Resources\MonitorApisResource::getNavigationBadgeColor())->toBeNull();
});

test('api monitor navigation badge excludes soft-deleted records', function () {
    $user = $this->actingAsSuperAdmin();

    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);

    $trashed = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    $trashed->delete();

    \App\Filament\Resources\MonitorApisResource::flushUnhealthyNavigationBadgeCache();

    expect(\App\Filament\Resources\MonitorApisResource::getNavigationBadge())->toBe('1')
        ->and(\App\Filament\Resources\MonitorApisResource::getNavigationBadgeColor())->toBeNull();
});
