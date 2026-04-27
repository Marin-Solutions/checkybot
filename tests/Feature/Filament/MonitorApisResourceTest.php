<?php

use App\Filament\Resources\MonitorApisResource\Pages\CreateMonitorApis;
use App\Filament\Resources\MonitorApisResource\Pages\EditMonitorApis;
use App\Filament\Resources\MonitorApisResource\Pages\ListMonitorApis;
use App\Filament\Resources\MonitorApisResource\Pages\ViewMonitorApis;
use App\Filament\Resources\MonitorApisResource\RelationManagers\AssertionsRelationManager;
use App\Filament\Resources\MonitorApisResource\RelationManagers\ResultsRelationManager;
use App\Models\MonitorApiAssertion;
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
            'request_body_type' => 'json',
            'request_body' => '{"email":"monitor@example.com","password":"secret"}',
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
        ->and($monitor->request_body_type)->toBe('json')
        ->and($monitor->request_body)->toBe('{"email":"monitor@example.com","password":"secret"}')
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
        'request_body_type' => null,
        'request_body' => null,
    ]);

    Livewire::test(EditMonitorApis::class, ['record' => $monitor->id])
        ->fillForm([
            'http_method' => 'PATCH',
            'expected_status' => 202,
            'timeout_seconds' => 30,
            'is_enabled' => false,
            'request_body_type' => 'raw',
            'request_body' => 'status=active',
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
        ->and($monitor->request_body_type)->toBe('raw')
        ->and($monitor->request_body)->toBe('status=active')
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

test('api monitor list shows effective polling interval', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Cadenced API',
        'package_interval' => '15m',
        'last_heartbeat_at' => now()->subMinutes(10),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$monitor])
        ->assertTableColumnExists('package_interval')
        ->assertSee('15m')
        ->assertSee('Expected heartbeat every 15m');
});

test('api monitor list shows scheduler-rounded cadence for second-based intervals', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Second cadence API',
        'package_interval' => '90s',
        'last_heartbeat_at' => now()->subMinute(),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$monitor])
        ->assertTableColumnExists('package_interval')
        ->assertSee('2m')
        ->assertSee('Expected heartbeat every 2m');
});

test('api monitor list shows default cadence when polling interval is invalid', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Invalid interval API',
        'package_interval' => 'bad_value',
        'last_heartbeat_at' => now(),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$monitor])
        ->assertSee('bad_value')
        ->assertSee('Runs every minute');
});

test('super admin can toggle is_enabled inline from the api monitors table', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->call('updateTableColumnState', 'is_enabled', $monitor->getKey(), false)
        ->assertNotified();

    expect($monitor->refresh()->is_enabled)->toBeFalse();

    Livewire::test(ListMonitorApis::class)
        ->call('updateTableColumnState', 'is_enabled', $monitor->getKey(), true)
        ->assertNotified();

    expect($monitor->refresh()->is_enabled)->toBeTrue();
});

test('user without Update:MonitorApis permission cannot toggle is_enabled inline', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo(['ViewAny:MonitorApis', 'View:MonitorApis']);
    $this->actingAs($user);

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);

    // The inline toggle is gated server-side via the disabled() callback,
    // which short-circuits Filament's updateTableColumnState (returns null)
    // before beforeStateUpdated runs — so the Livewire response is 200, not
    // 403, and assertForbidden() would actually fail here. Instead we assert
    // the column reports isDisabled() === true for this user (the real gate)
    // and that an attempted Livewire toggle leaves the value unchanged.
    Livewire::test(ListMonitorApis::class)
        ->assertTableColumnExists(
            'is_enabled',
            fn (\Filament\Tables\Columns\ToggleColumn $column): bool => $column->isDisabled(),
            $monitor,
        )
        ->call('updateTableColumnState', 'is_enabled', $monitor->getKey(), false);

    expect($monitor->refresh()->is_enabled)->toBeTrue();
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
        'request_body_type' => 'json',
        'request_body' => '{"probe":true}',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableAction('test', $monitor);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://example.com/health'
        && $request->data() === ['probe' => true]);
});

test('list test action flashes success notification with status code and response time', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Healthy API',
        'url' => 'https://example.com/health',
        'http_method' => 'GET',
        'expected_status' => 200,
        'data_path' => 'data.status',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableAction('test', $monitor)
        ->assertNotified('API response received');
});

test('list test action flashes danger notification when the upstream returns a server error', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response(['error' => 'boom'], 500),
    ]);

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Broken API',
        'url' => 'https://example.com/broken',
        'http_method' => 'GET',
        'expected_status' => 200,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableAction('test', $monitor)
        ->assertNotified('API request failed');
});

test('list test action flashes warning notification when an assertion fails', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response(['data' => []], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Partial API',
        'url' => 'https://example.com/partial',
        'http_method' => 'GET',
        'expected_status' => 200,
        'data_path' => 'data.status',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableAction('test', $monitor)
        ->assertNotified('Some API assertions failed');
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

test('view page run now action persists a real run and surfaces evidence', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Run Now API',
        'url' => 'https://example.com/run-now',
        'http_method' => 'GET',
        'expected_status' => 200,
        'data_path' => 'data.status',
        'current_status' => null,
        'status_summary' => null,
        'last_heartbeat_at' => null,
    ]);

    expect($monitor->results)->toHaveCount(0);

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->callAction('run_now')
        ->assertNotified('On-demand run succeeded');

    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://example.com/run-now');

    $monitor->refresh();

    expect($monitor->results()->count())->toBe(1)
        ->and($monitor->results()->latest('id')->first()->status)->toBe('healthy')
        ->and($monitor->current_status)->toBeNull()
        ->and($monitor->last_heartbeat_at)->toBeNull()
        ->and($monitor->status_summary)->toBeNull();
});

test('view page run now action surfaces failure evidence and persists the failed run', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response(['error' => 'boom'], 500),
    ]);

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Broken Run Now API',
        'url' => 'https://example.com/broken-run',
        'http_method' => 'GET',
        'expected_status' => 200,
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(5),
        'status_summary' => 'API responded as expected.',
    ]);

    $heartbeatBefore = $monitor->last_heartbeat_at;

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->callAction('run_now')
        ->assertNotified('On-demand run failed');

    $monitor->refresh();

    expect($monitor->results()->count())->toBe(1)
        ->and($monitor->results()->first()->http_code)->toBe(500)
        ->and($monitor->results()->first()->status)->toBe('danger')
        ->and($monitor->current_status)->toBe('healthy')
        ->and($monitor->last_heartbeat_at?->equalTo($heartbeatBefore))->toBeTrue()
        ->and($monitor->status_summary)->toBe('API responded as expected.');
});

test('view page hides run now action when api monitor is disabled', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->disabled()->create([
        'created_by' => $user->id,
    ]);

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->assertActionHidden('run_now');
});

test('view page hides run now action for users without update permission', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo(['ViewAny:MonitorApis', 'View:MonitorApis']);
    $this->actingAs($user);

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->assertActionHidden('run_now');
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

test('super admin can bulk disable api monitors', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitors = MonitorApis::factory()->count(3)->create([
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('disable', $monitors);

    foreach ($monitors as $monitor) {
        expect($monitor->refresh()->is_enabled)->toBeFalse();
    }
});

test('super admin can bulk enable api monitors', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitors = MonitorApis::factory()->disabled()->count(3)->create([
        'created_by' => $user->id,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('enable', $monitors);

    foreach ($monitors as $monitor) {
        expect($monitor->refresh()->is_enabled)->toBeTrue();
    }
});

test('super admin can bulk change the interval of api monitors', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitors = MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'package_interval' => '5m',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('changeInterval', $monitors, data: [
            'interval' => '15m',
        ]);

    foreach ($monitors as $monitor) {
        expect($monitor->refresh()->package_interval)->toBe('15m');
    }
});

test('bulk change interval on monitors already at the target interval notifies that nothing changed', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitors = MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'package_interval' => '15m',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('changeInterval', $monitors, data: [
            'interval' => '15m',
        ])
        ->assertNotified('Nothing to update');

    foreach ($monitors as $monitor) {
        expect($monitor->refresh()->package_interval)->toBe('15m');
    }
});

test('bulk disable on already disabled api monitors notifies that nothing changed', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitors = MonitorApis::factory()->disabled()->count(2)->create([
        'created_by' => $user->id,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('disable', $monitors)
        ->assertNotified('Nothing to disable');

    foreach ($monitors as $monitor) {
        expect($monitor->refresh()->is_enabled)->toBeFalse();
    }
});

test('bulk enable on already enabled api monitors notifies that nothing changed', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitors = MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('enable', $monitors)
        ->assertNotified('Nothing to enable');

    foreach ($monitors as $monitor) {
        expect($monitor->refresh()->is_enabled)->toBeTrue();
    }
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

test('api monitor evidence infolist mounts cleanly for failed assertions with actual and expected', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
    ]);

    $result = MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'status' => 'danger',
        'summary' => 'API heartbeat failed with HTTP status 200.',
        'http_code' => 200,
        'failed_assertions' => [[
            'path' => 'data.status',
            'type' => 'value_compare',
            'message' => 'Value comparison failed: expected = active',
            'actual' => 'pending',
            'expected' => '= active',
        ]],
    ]);

    Livewire::test(ResultsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => ViewMonitorApis::class,
    ])
        ->mountTableAction('view', $result)
        ->assertHasNoTableActionErrors();

    $normalized = \App\Support\ApiMonitorEvidenceFormatter::normalizeAssertions($result->failed_assertions);

    expect($normalized[0]['actual'])->toBe('pending')
        ->and($normalized[0]['expected'])->toBe('= active');
});

test('api assertion preview action shows actual versus expected from saved response', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'active',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'http_code' => 200,
        'response_time_ms' => 98,
        'response_body' => ['data' => ['status' => 'pending']],
    ]);

    Livewire::test(AssertionsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => ViewMonitorApis::class,
    ])
        ->assertTableActionExists('preview', null, $assertion)
        ->assertTableActionHasLabel('preview', 'Preview', $assertion)
        ->mountTableAction('preview', $assertion)
        ->assertHasNoTableActionErrors()
        ->assertSchemaStateSet([
            'preview_source' => 'Latest saved response',
            'preview_result' => 'Failed',
            'preview_actual' => 'pending',
            'preview_expected' => '= active',
        ]);
});

test('super admin can filter api monitors by current status', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $healthy = MonitorApis::factory()->create(['created_by' => $user->id, 'current_status' => 'healthy']);
    $warning = MonitorApis::factory()->create(['created_by' => $user->id, 'current_status' => 'warning']);
    $danger = MonitorApis::factory()->create(['created_by' => $user->id, 'current_status' => 'danger']);
    $unknownNull = MonitorApis::factory()->create(['created_by' => $user->id, 'current_status' => null]);
    // PackageSyncService::disableMissingApiChecks and CheckybotControlService::disableCheck
    // persist the literal string 'unknown', so the filter must surface that representation too.
    $unknownLiteral = MonitorApis::factory()->create(['created_by' => $user->id, 'current_status' => 'unknown']);

    Livewire::test(ListMonitorApis::class)
        ->filterTable('current_status', 'danger')
        ->assertCanSeeTableRecords([$danger])
        ->assertCanNotSeeTableRecords([$healthy, $warning, $unknownNull, $unknownLiteral]);

    Livewire::test(ListMonitorApis::class)
        ->filterTable('current_status', 'warning')
        ->assertCanSeeTableRecords([$warning])
        ->assertCanNotSeeTableRecords([$healthy, $danger, $unknownNull, $unknownLiteral]);

    Livewire::test(ListMonitorApis::class)
        ->filterTable('current_status', 'healthy')
        ->assertCanSeeTableRecords([$healthy])
        ->assertCanNotSeeTableRecords([$warning, $danger, $unknownNull, $unknownLiteral]);

    Livewire::test(ListMonitorApis::class)
        ->filterTable('current_status', 'unknown')
        ->assertCanSeeTableRecords([$unknownNull, $unknownLiteral])
        ->assertCanNotSeeTableRecords([$healthy, $warning, $danger]);
});

test('super admin can filter api monitors to only failing', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $healthy = MonitorApis::factory()->create(['created_by' => $user->id, 'current_status' => 'healthy']);
    $warning = MonitorApis::factory()->create(['created_by' => $user->id, 'current_status' => 'warning']);
    $danger = MonitorApis::factory()->create(['created_by' => $user->id, 'current_status' => 'danger']);
    $unknown = MonitorApis::factory()->create(['created_by' => $user->id, 'current_status' => null]);
    $disabledWarning = MonitorApis::factory()->disabled()->create([
        'created_by' => $user->id,
        'current_status' => 'warning',
    ]);
    $disabledDanger = MonitorApis::factory()->disabled()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->filterTable('only_failing', true)
        ->assertCanSeeTableRecords([$warning, $danger])
        ->assertCanNotSeeTableRecords([$healthy, $unknown, $disabledWarning, $disabledDanger]);
});

test('user without Update:MonitorApis permission cannot see API monitor bulk actions', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo('ViewAny:MonitorApis');
    $this->actingAs($user);

    MonitorApis::factory()->count(2)->create(['created_by' => $user->id]);

    Livewire::test(ListMonitorApis::class)
        ->assertTableBulkActionHidden('enable')
        ->assertTableBulkActionHidden('disable')
        ->assertTableBulkActionHidden('changeInterval');
});

test('soft-deleted API monitors are not counted in bulk disable notification', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $active = MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);

    $trashed = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);
    $trashed->delete();

    $selection = $active->toBase()->concat([$trashed]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('disable', $selection)
        ->assertNotified('2 API monitors disabled');

    foreach ($active as $monitor) {
        expect($monitor->refresh()->is_enabled)->toBeFalse();
    }

    expect(MonitorApis::withTrashed()->find($trashed->id)->is_enabled)->toBeTrue();
});

test('soft-deleted API monitors are not counted in bulk enable notification', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $active = MonitorApis::factory()->disabled()->count(2)->create([
        'created_by' => $user->id,
    ]);

    $trashed = MonitorApis::factory()->disabled()->create([
        'created_by' => $user->id,
    ]);
    $trashed->delete();

    $selection = $active->toBase()->concat([$trashed]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('enable', $selection)
        ->assertNotified('2 API monitors enabled');

    foreach ($active as $monitor) {
        expect($monitor->refresh()->is_enabled)->toBeTrue();
    }

    expect(MonitorApis::withTrashed()->find($trashed->id)->is_enabled)->toBeFalse();
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

test('api monitor list shows all four health status tabs', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Livewire::test(ListMonitorApis::class)
        ->assertSee('All')
        ->assertSee('Failing')
        ->assertSee('Disabled')
        ->assertSee('Recently Recovered');
});

test('api monitor list failing tab only shows warning and danger monitors', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $healthy = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    $warning = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'warning',
    ]);
    $danger = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->set('activeTab', 'failing')
        ->assertCanSeeTableRecords([$warning, $danger])
        ->assertCanNotSeeTableRecords([$healthy]);
});

test('api monitor list disabled tab only shows monitors with is_enabled false', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $enabled = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);
    $disabled = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => false,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->set('activeTab', 'disabled')
        ->assertCanSeeTableRecords([$disabled])
        ->assertCanNotSeeTableRecords([$enabled]);
});

test('api monitor list recently recovered tab requires healthy status with prior failure in window', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $recovered = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $recovered->id,
        'status' => 'danger',
        'created_at' => now()->subHours(2),
    ]);

    $stillHealthy = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $stillHealthy->id,
        'status' => 'healthy',
        'created_at' => now()->subHours(2),
    ]);

    $stillFailing = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $stillFailing->id,
        'status' => 'danger',
        'created_at' => now()->subMinutes(10),
    ]);

    $oldRecovery = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $oldRecovery->id,
        'status' => 'danger',
        'created_at' => now()->subDays(3),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->set('activeTab', 'recently_recovered')
        ->assertCanSeeTableRecords([$recovered])
        ->assertCanNotSeeTableRecords([$stillHealthy, $stillFailing, $oldRecovery]);
});

test('api monitor list all tab shows every visible monitor regardless of health', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $healthy = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
        'is_enabled' => true,
    ]);
    $warning = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'warning',
        'is_enabled' => true,
    ]);
    $disabled = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
        'is_enabled' => false,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$healthy, $warning, $disabled]);
});

test('api monitor list tab badges report accurate per-tab counts', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'warning',
    ]);
    MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
        'is_enabled' => false,
    ]);

    $recovered = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
        'is_enabled' => true,
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $recovered->id,
        'status' => 'danger',
        'created_at' => now()->subHours(2),
    ]);

    $page = Livewire::test(ListMonitorApis::class)->instance();

    expect(invade($page)->resolveTabCounts())->toMatchArray([
        'failing' => 3,
        'disabled' => 2,
        'recently_recovered' => 1,
    ]);
});

test('api monitor list tab badges scope counts to the current user', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $otherUser = User::factory()->create();

    MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    MonitorApis::factory()->count(5)->create([
        'created_by' => $otherUser->id,
        'current_status' => 'danger',
    ]);

    $page = Livewire::test(ListMonitorApis::class)->instance();

    expect(invade($page)->resolveTabCounts()['failing'])->toBe(2);
});

test('api monitor list tab badges exclude soft-deleted monitors', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    $trashed = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    $trashed->delete();

    $page = Livewire::test(ListMonitorApis::class)->instance();

    expect(invade($page)->resolveTabCounts()['failing'])->toBe(2);
});

test('api monitor list failing tab excludes soft-deleted monitors', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $visible = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    $trashed = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    $trashed->delete();

    Livewire::test(ListMonitorApis::class)
        ->set('activeTab', 'failing')
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$trashed]);
});

test('api monitor list shows empty state with create CTA when no monitors exist', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Livewire::test(ListMonitorApis::class)
        ->assertSee('No API monitors yet')
        ->assertSee('Add your first API monitor to start tracking response time, status codes, and assertions on a schedule.')
        ->assertSee('Add API monitor');
});
