<?php

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Filament\Resources\MonitorApisResource;
use App\Filament\Resources\MonitorApisResource\Pages\CreateMonitorApis;
use App\Filament\Resources\MonitorApisResource\Pages\EditMonitorApis;
use App\Filament\Resources\MonitorApisResource\Pages\ListMonitorApis;
use App\Filament\Resources\MonitorApisResource\Pages\ViewMonitorApis;
use App\Filament\Resources\MonitorApisResource\RelationManagers\AssertionsRelationManager;
use App\Filament\Resources\MonitorApisResource\RelationManagers\NotificationSettingsRelationManager;
use App\Filament\Resources\MonitorApisResource\RelationManagers\ResultsRelationManager;
use App\Jobs\RunApiMonitorDiagnosticJob;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

afterEach(function () {
    Carbon::setTestNow();
});

test('super admin can create api monitor with execution settings', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Checkout API',
            'url' => 'https://example.com/health',
            'http_method' => 'POST',
            'expected_status' => 204,
            'timeout_seconds' => 45,
            'max_response_time_ms' => 5000,
            'is_enabled' => false,
            'project_id' => $project->id,
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
        ->and($monitor->max_response_time_ms)->toBe(5000)
        ->and($monitor->project_id)->toBe($project->id)
        ->and($monitor->package_interval)->toBe('5m')
        ->and($monitor->is_enabled)->toBeFalse()
        ->and($monitor->data_path)->toBe('data.status')
        ->and($monitor->headers)->toBe(['Authorization' => 'Bearer secret'])
        ->and($monitor->request_body_type)->toBe('json')
        ->and($monitor->request_body)->toBe('{"email":"monitor@example.com","password":"secret"}')
        ->and($monitor->save_failed_response)->toBeFalse();
});

test('super admin cannot create api monitor for another users application', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();
    $otherProject = Project::factory()->create();

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Foreign Application API',
            'url' => 'https://example.com/health',
            'expected_status' => 200,
            'project_id' => $otherProject->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['project_id']);

    expect(MonitorApis::query()->where('title', 'Foreign Application API')->exists())->toBeFalse();
});

test('super admin can create api monitor with first run assertions', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Orders API',
            'url' => 'https://example.com/orders/health',
            'expected_status' => 200,
            'assertions' => [
                [
                    'data_path' => 'data.status',
                    'assertion_type' => 'value_compare',
                    'comparison_operator' => '=',
                    'expected_value' => 'ok',
                    'is_active' => true,
                ],
                [
                    'data_path' => 'data.items',
                    'assertion_type' => 'array_length',
                    'comparison_operator' => '>=',
                    'expected_value' => '1',
                    'is_active' => true,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $monitor = MonitorApis::query()
        ->where('title', 'Orders API')
        ->with('assertions')
        ->firstOrFail();

    expect($monitor->created_by)->toBe($user->id)
        ->and($monitor->assertions)->toHaveCount(2)
        ->and($monitor->assertions[0]->data_path)->toBe('data.status')
        ->and($monitor->assertions[0]->assertion_type)->toBe('value_compare')
        ->and($monitor->assertions[0]->comparison_operator)->toBe('=')
        ->and($monitor->assertions[0]->expected_value)->toBe('ok')
        ->and($monitor->assertions[0]->sort_order)->toBe(1)
        ->and($monitor->assertions[1]->data_path)->toBe('data.items')
        ->and($monitor->assertions[1]->assertion_type)->toBe('array_length')
        ->and($monitor->assertions[1]->comparison_operator)->toBe('>=')
        ->and($monitor->assertions[1]->expected_value)->toBe('1')
        ->and($monitor->assertions[1]->sort_order)->toBe(2);
});

test('super admin cannot create api monitor with invalid regex assertion', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Regex API',
            'url' => 'https://example.com/regex-health',
            'expected_status' => 200,
            'assertions' => [
                [
                    'data_path' => 'data.version',
                    'assertion_type' => 'regex_match',
                    'regex_pattern' => '[invalid',
                    'is_active' => true,
                ],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['assertions.0.regex_pattern']);
});

test('super admin cannot create api monitor with contains array length assertion', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Array Length API',
            'url' => 'https://example.com/array-length-health',
            'expected_status' => 200,
            'assertions' => [
                [
                    'data_path' => 'data.items',
                    'assertion_type' => 'array_length',
                    'comparison_operator' => 'contains',
                    'expected_value' => '1',
                    'is_active' => true,
                ],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['assertions.0.comparison_operator']);
});

test('super admin cannot create api monitor with non numeric array length assertion', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Non Numeric Array Length API',
            'url' => 'https://example.com/non-numeric-array-length-health',
            'expected_status' => 200,
            'assertions' => [
                [
                    'data_path' => 'data.items',
                    'assertion_type' => 'array_length',
                    'comparison_operator' => '>=',
                    'expected_value' => 'many',
                    'is_active' => true,
                ],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['assertions.0.expected_value']);
});

test('super admin can update api monitor execution settings', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'http_method' => 'GET',
        'expected_status' => 200,
        'timeout_seconds' => null,
        'is_enabled' => true,
        'current_status' => 'danger',
        'status_summary' => 'API check failed.',
        'last_heartbeat_at' => now()->subMinutes(10),
        'stale_at' => now()->subMinute(),
        'save_failed_response' => true,
        'request_body_type' => null,
        'request_body' => null,
    ]);

    Livewire::test(EditMonitorApis::class, ['record' => $monitor->id])
        ->fillForm([
            'http_method' => 'PATCH',
            'expected_status' => 202,
            'timeout_seconds' => 30,
            'package_interval' => '15m',
            'is_enabled' => false,
            'project_id' => $project->id,
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
        ->and($monitor->package_interval)->toBe('15m')
        ->and($monitor->project_id)->toBe($project->id)
        ->and($monitor->is_enabled)->toBeFalse()
        ->and($monitor->current_status)->toBe('unknown')
        ->and($monitor->status_summary)->toBe('Disabled in Checkybot admin.')
        ->and($monitor->last_heartbeat_at)->toBeNull()
        ->and($monitor->stale_at)->toBeNull()
        ->and($monitor->request_body_type)->toBe('raw')
        ->and($monitor->request_body)->toBe('status=active')
        ->and($monitor->save_failed_response)->toBeFalse();
});

test('clearing request body type clears the stored request body', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'request_body_type' => 'json',
        'request_body' => '{"probe":true}',
        'package_interval' => '5m',
    ]);

    Livewire::test(EditMonitorApis::class, ['record' => $monitor->id])
        ->fillForm([
            'request_body_type' => null,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $monitor->refresh();

    expect($monitor->request_body_type)->toBeNull()
        ->and($monitor->request_body)->toBeNull();
});

test('hidden request body is not persisted when body type is cleared', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'request_body_type' => 'json',
        'request_body' => '{"probe":true}',
        'package_interval' => '5m',
    ]);

    Livewire::test(EditMonitorApis::class, ['record' => $monitor->id])
        ->fillForm([
            'request_body_type' => null,
            'request_body' => '{"stale":true}',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $monitor->refresh();

    expect($monitor->request_body_type)->toBeNull()
        ->and($monitor->request_body)->toBeNull();
});

test('super admin cannot create json api monitor with a scalar request body', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Scalar JSON API',
            'url' => 'https://example.com/health',
            'http_method' => 'POST',
            'request_body_type' => 'json',
            'request_body' => '42',
        ])
        ->call('create')
        ->assertHasFormErrors(['request_body']);
});

test('super admin cannot create form api monitor with a null request body scalar', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Null Form API',
            'url' => 'https://example.com/token',
            'http_method' => 'POST',
            'request_body_type' => 'form',
            'request_body' => 'null',
        ])
        ->call('create')
        ->assertHasFormErrors(['request_body']);
});

test('super admin cannot create json api monitor with a whitespace only request body', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Whitespace JSON API',
            'url' => 'https://example.com/health',
            'http_method' => 'POST',
            'request_body_type' => 'json',
            'request_body' => '   ',
        ])
        ->call('create')
        ->assertHasFormErrors(['request_body']);
});

test('super admin cannot create raw api monitor with an oversized whitespace request body', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Oversized Raw API',
            'url' => 'https://example.com/raw',
            'http_method' => 'POST',
            'request_body_type' => 'raw',
            'request_body' => str_repeat(' ', 65536),
        ])
        ->call('create')
        ->assertHasFormErrors(['request_body']);
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

test('api monitor list hides non-server freshness evidence', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-09 12:00:00'));

    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $legacyStale = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Package stale API',
        'source' => 'package',
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subMinutes(12),
        'stale_at' => now()->subMinutes(7),
    ]);

    $manualCheck = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Manual check API',
        'source' => 'manual',
        'last_heartbeat_at' => now()->subMinutes(3),
        'stale_at' => null,
    ]);

    $manualStale = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Manual stale API',
        'source' => 'manual',
        'last_heartbeat_at' => now()->subMinutes(10),
        'stale_at' => now()->subMinutes(2),
    ]);

    $manualAwaiting = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Manual awaiting API',
        'source' => 'manual',
        'last_heartbeat_at' => null,
        'stale_at' => null,
    ]);

    $manualDisabled = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Manual disabled API',
        'source' => 'manual',
        'is_enabled' => false,
        'last_heartbeat_at' => now()->subMinutes(4),
        'stale_at' => now()->subMinute(),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$legacyStale, $manualCheck, $manualStale, $manualAwaiting, $manualDisabled])
        ->assertTableColumnDoesNotExist('freshness_evidence')
        ->assertDontSee('Freshness')
        ->assertDontSee('Check received')
        ->assertDontSee('Awaiting check')
        ->assertDontSee('Marked stale')
        ->assertDontSee('Scheduled API checks are not expected');
});

test('api monitor edit page exposes api notification management', function () {
    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create(['created_by' => $user->id]);

    Livewire::test(EditMonitorApis::class, ['record' => $monitor->id])
        ->assertSuccessful()
        ->assertSee('API Notifications');

    expect(MonitorApisResource::getRelations())
        ->toContain(NotificationSettingsRelationManager::class);
});

test('api notification relation manager only shows alerts for the current api monitor', function () {
    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create(['created_by' => $user->id]);
    $otherMonitor = MonitorApis::factory()->create(['created_by' => $user->id]);

    $visibleSetting = NotificationSetting::factory()->apiMonitorScope()->email()->create([
        'user_id' => $user->id,
        'monitor_api_id' => $monitor->id,
    ]);
    $hiddenSetting = NotificationSetting::factory()->apiMonitorScope()->email()->create([
        'user_id' => $user->id,
        'monitor_api_id' => $otherMonitor->id,
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => EditMonitorApis::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visibleSetting])
        ->assertCanNotSeeTableRecords([$hiddenSetting]);
});

test('api notification relation manager filters delivery outcome channel type and inactive rules', function () {
    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create(['created_by' => $user->id]);

    $failedEmail = NotificationSetting::factory()->apiMonitorScope()->email()->create([
        'user_id' => $user->id,
        'monitor_api_id' => $monitor->id,
        'last_delivery_succeeded' => false,
        'last_delivery_attempted_at' => now(),
    ]);

    $untestedWebhook = NotificationSetting::factory()->apiMonitorScope()->webhook()->create([
        'user_id' => $user->id,
        'monitor_api_id' => $monitor->id,
        'last_delivery_attempted_at' => null,
    ]);

    $inactiveEmail = NotificationSetting::factory()->apiMonitorScope()->email()->inactive()->create([
        'user_id' => $user->id,
        'monitor_api_id' => $monitor->id,
        'last_delivery_succeeded' => true,
        'last_delivery_attempted_at' => now(),
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => EditMonitorApis::class,
    ])
        ->filterTable('delivery_outcome', 'failed')
        ->assertCanSeeTableRecords([$failedEmail])
        ->assertCanNotSeeTableRecords([$untestedWebhook, $inactiveEmail]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => EditMonitorApis::class,
    ])
        ->filterTable('delivery_outcome', 'untested')
        ->assertCanSeeTableRecords([$untestedWebhook])
        ->assertCanNotSeeTableRecords([$failedEmail, $inactiveEmail]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => EditMonitorApis::class,
    ])
        ->filterTable('channel_type', NotificationChannelTypesEnum::WEBHOOK->value)
        ->assertCanSeeTableRecords([$untestedWebhook])
        ->assertCanNotSeeTableRecords([$failedEmail, $inactiveEmail]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => EditMonitorApis::class,
    ])
        ->filterTable('rule_state', 'inactive')
        ->assertCanSeeTableRecords([$inactiveEmail])
        ->assertCanNotSeeTableRecords([$failedEmail, $untestedWebhook]);
});

test('super admin can create api-scoped email notification from api monitor page', function () {
    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create(['created_by' => $user->id]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => EditMonitorApis::class,
    ])
        ->callTableAction('create', data: [
            'inspection' => WebsiteServicesEnum::API_MONITOR->value,
            'channel_type' => NotificationChannelTypesEnum::MAIL->value,
            'address' => 'endpoint-ops@example.com',
            'flag_active' => true,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('notification_settings', [
        'user_id' => $user->id,
        'website_id' => null,
        'monitor_api_id' => $monitor->id,
        'scope' => NotificationScopesEnum::API_MONITOR->value,
        'inspection' => WebsiteServicesEnum::API_MONITOR->value,
        'channel_type' => NotificationChannelTypesEnum::MAIL->value,
        'address' => 'endpoint-ops@example.com',
        'flag_active' => true,
    ]);
});

test('super admin can create api-scoped webhook notification from api monitor page', function () {
    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create(['created_by' => $user->id]);
    $channel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'title' => 'Endpoint Hook',
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => EditMonitorApis::class,
    ])
        ->callTableAction('create', data: [
            'inspection' => WebsiteServicesEnum::ALL_CHECK->value,
            'channel_type' => NotificationChannelTypesEnum::WEBHOOK->value,
            'notification_channel_id' => $channel->id,
            'flag_active' => true,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('notification_settings', [
        'user_id' => $user->id,
        'website_id' => null,
        'monitor_api_id' => $monitor->id,
        'scope' => NotificationScopesEnum::API_MONITOR->value,
        'inspection' => WebsiteServicesEnum::ALL_CHECK->value,
        'channel_type' => NotificationChannelTypesEnum::WEBHOOK->value,
        'notification_channel_id' => $channel->id,
        'address' => null,
        'flag_active' => true,
    ]);
});

test('api-scoped webhook notification cannot reuse another users channel', function () {
    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create(['created_by' => $user->id]);
    $otherChannel = NotificationChannels::factory()->create([
        'title' => 'External Hook',
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => EditMonitorApis::class,
    ])
        ->callTableAction('create', data: [
            'inspection' => WebsiteServicesEnum::ALL_CHECK->value,
            'channel_type' => NotificationChannelTypesEnum::WEBHOOK->value,
            'notification_channel_id' => $otherChannel->id,
            'flag_active' => true,
        ])
        ->assertHasTableActionErrors(['notification_channel_id']);

    $this->assertDatabaseMissing('notification_settings', [
        'monitor_api_id' => $monitor->id,
        'notification_channel_id' => $otherChannel->id,
    ]);
});

test('api monitor list relabels the table diagnostic action', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertTableActionExists('run_now', null, $monitor)
        ->assertTableActionDoesNotExist('test', null, $monitor)
        ->assertTableActionHasLabel('run_now', 'Run check now', $monitor)
        ->assertTableActionHasIcon('run_now', 'heroicon-o-bolt', $monitor);
});

test('api monitor list disables run now action while diagnostic is queued', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
        'diagnostic_queued_at' => now(),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertTableActionDisabled('run_now', $monitor);
});

test('api monitor list hides run now action for archived monitors', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);
    $monitor->delete();

    Livewire::test(ListMonitorApis::class)
        ->filterTable('trashed', 'only')
        ->assertCanSeeTableRecords([$monitor])
        ->assertTableActionHidden('run_now', $monitor);
});

test('api monitor list average response time excludes on demand diagnostic runs', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_time_ms' => 100,
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_time_ms' => 300,
    ]);
    MonitorApiResult::factory()->onDemand()->create([
        'monitor_api_id' => $monitor->id,
        'response_time_ms' => 3000,
    ]);

    $listedMonitor = MonitorApisResource::getEloquentQuery()->findOrFail($monitor->id);

    expect((float) $listedMonitor->avg_response_time)->toBe(200.0);
});

test('api monitor list default render skips optional history aggregates and recovered counts', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitors = MonitorApis::factory()->count(5)->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);

    foreach ($monitors as $monitor) {
        MonitorApiResult::factory()->successful()->create([
            'monitor_api_id' => $monitor->id,
            'response_time_ms' => 120,
        ]);
    }

    $sql = [];

    \DB::listen(function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    Livewire::test(ListMonitorApis::class)
        ->assertSuccessful();

    $joinedSql = implode("\n", $sql);

    expect($joinedSql)
        ->not->toContain('avg(')
        ->not->toContain('where exists')
        ->not->toContain('streak_count');
});

test('api monitor list shows compact latest result evidence', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $assertionOnlyFailure = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Assertion API',
        'current_status' => 'danger',
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $assertionOnlyFailure->id,
        'is_success' => false,
        'status' => 'danger',
        'http_code' => 200,
        'transport_error_type' => null,
        'failed_assertions' => [
            [
                'path' => 'data.status',
                'type' => 'value_compare',
                'message' => 'Expected active, got pending.',
            ],
            [
                'path' => 'data.ready',
                'type' => 'exists',
                'message' => 'Expected ready flag to exist.',
            ],
        ],
    ]);

    $transportFailure = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'DNS API',
        'current_status' => 'danger',
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $transportFailure->id,
        'is_success' => false,
        'status' => 'danger',
        'http_code' => 0,
        'transport_error_type' => null,
        'failed_assertions' => null,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$assertionOnlyFailure, $transportFailure])
        ->assertTableColumnExists('latest_failure_evidence')
        ->assertSee('HTTP 200 | ok | 2 failed | data.status')
        ->assertSee('HTTP 0 | no response | 0 failed | -');
});

test('api monitor list shows scheduled failure streak evidence', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Streak API',
        'current_status' => 'danger',
    ]);

    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $monitor->id,
        'created_at' => now()->subMinutes(30),
    ]);
    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $monitor->id,
        'created_at' => now()->subMinutes(15),
    ]);
    MonitorApiResult::factory()->failed()->onDemand()->create([
        'monitor_api_id' => $monitor->id,
        'created_at' => now()->subMinutes(12),
    ]);
    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $monitor->id,
        'created_at' => now()->subMinutes(5),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$monitor])
        ->assertTableColumnExists('scheduled_failure_streak')
        ->assertSee('2 scheduled failures')
        ->assertSee('First failed 15 minutes ago');
});

test('api monitor list shows effective polling interval', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Cadenced API',
        'package_interval' => '15m',
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$monitor])
        ->assertTableColumnExists('package_interval')
        ->assertSee('15m')
        ->assertSee('Next scheduled run')
        ->assertSee('Expected every 15m');
});

test('api monitor list shows scheduler-rounded cadence for second-based intervals', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Second cadence API',
        'package_interval' => '90s',
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$monitor])
        ->assertTableColumnExists('package_interval')
        ->assertSee('2m')
        ->assertSee('Expected every 2m');
});

test('api monitor list shows default cadence when polling interval is invalid', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Invalid interval API',
        'package_interval' => 'bad_value',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$monitor])
        ->assertSee('bad_value')
        ->assertSee('Schedule value bad_value cannot be evaluated');
});

test('api monitor list flags missing legacy polling intervals instead of implying every-minute scheduling is intentional', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Legacy API',
        'package_interval' => null,
        'last_heartbeat_at' => now(),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertCanSeeTableRecords([$monitor])
        ->assertSee('Missing')
        ->assertSee('No polling interval is configured');
});

test('super admin can toggle is_enabled inline from the api monitors table', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
        'current_status' => 'danger',
        'status_summary' => 'API check failed.',
        'last_heartbeat_at' => now()->subMinutes(10),
        'stale_at' => now()->subMinute(),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->call('updateTableColumnState', 'is_enabled', $monitor->getKey(), false)
        ->assertNotified();

    expect($monitor->refresh()->is_enabled)->toBeFalse()
        ->and($monitor->current_status)->toBe('unknown')
        ->and($monitor->status_summary)->toBe('Disabled in Checkybot admin.')
        ->and($monitor->last_heartbeat_at)->toBeNull()
        ->and($monitor->stale_at)->toBeNull();

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

test('list run now action queues diagnostic job without running outbound request inline', function () {
    $this->createResourcePermissions('MonitorApis');
    Carbon::setTestNow('2026-04-24 12:00:00');

    $user = $this->actingAsSuperAdmin();

    Queue::fake();
    Http::preventStrayRequests();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'POST health',
        'url' => 'https://example.com/health',
        'http_method' => 'POST',
        'expected_status' => 200,
        'timeout_seconds' => 30,
        'data_path' => 'data.status',
        'request_body_type' => 'json',
        'request_body' => '{"probe":true}',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableAction('run_now', $monitor)
        ->assertNotified('Diagnostic queued');

    Queue::assertPushed(RunApiMonitorDiagnosticJob::class, fn (RunApiMonitorDiagnosticJob $job): bool => $job->monitor->is($monitor));

    $monitor->refresh();

    expect($monitor->results()->count())->toBe(0)
        ->and($monitor->diagnostic_queued_at?->toDateTimeString())->toBe('2026-04-24 12:00:00');

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->assertSuccessful()
        ->assertSee('Latest Manual Run')
        ->assertSee('Manual Run Status')
        ->assertSee('Queued')
        ->assertSee('Queued At')
        ->assertSee('Apr 24, 2026');
});

test('list run now action leaves live status unchanged while diagnostic is queued', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Queue::fake();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Healthy API',
        'url' => 'https://example.com/health',
        'http_method' => 'GET',
        'expected_status' => 200,
        'data_path' => 'data.status',
        'current_status' => null,
        'last_heartbeat_at' => null,
        'status_summary' => null,
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableAction('run_now', $monitor)
        ->assertNotified('Diagnostic queued');

    $monitor->refresh();

    expect($monitor->results()->count())->toBe(0)
        ->and($monitor->current_status)->toBeNull()
        ->and($monitor->last_heartbeat_at)->toBeNull()
        ->and($monitor->status_summary)->toBeNull();
});

test('list run now action queues failure-prone endpoints without moving live status', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Queue::fake();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Broken API',
        'url' => 'https://example.com/broken',
        'http_method' => 'GET',
        'expected_status' => 200,
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(5),
        'status_summary' => 'API responded as expected.',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableAction('run_now', $monitor)
        ->assertNotified('Diagnostic queued');

    Queue::assertPushed(RunApiMonitorDiagnosticJob::class, fn (RunApiMonitorDiagnosticJob $job): bool => $job->monitor->is($monitor));

    $monitor->refresh();

    expect($monitor->results()->count())->toBe(0)
        ->and($monitor->current_status)->toBe('healthy')
        ->and($monitor->last_heartbeat_at)->toBeNull()
        ->and($monitor->status_summary)->toBe('API responded as expected.');
});

test('list run now action queues diagnostics for degraded assertion cases', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Queue::fake();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Partial API',
        'url' => 'https://example.com/partial',
        'http_method' => 'GET',
        'expected_status' => 200,
        'data_path' => 'data.status',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableAction('run_now', $monitor)
        ->assertNotified('Diagnostic queued');

    Queue::assertPushed(RunApiMonitorDiagnosticJob::class, fn (RunApiMonitorDiagnosticJob $job): bool => $job->monitor->is($monitor));
    expect($monitor->results()->count())->toBe(0);
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

test('check api action caps interactive timeout and disables interactive retries', function () {
    $this->createResourcePermissions('MonitorApis');

    $this->actingAsSuperAdmin();

    config([
        'monitor.api_retries' => 3,
        'monitor.api_interactive_timeout' => 4,
        'monitor.api_interactive_retries' => 0,
    ]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Transport error while testing API'
            && $context['timeout'] === 4
            && $context['retries'] === 0)
        ->andReturnNull();

    Http::fake(function (): never {
        throw new \Illuminate\Http\Client\ConnectionException('timeout');
    });

    Livewire::test(CreateMonitorApis::class)
        ->fillForm([
            'title' => 'Slow API',
            'url' => 'https://example.com/slow-health',
            'http_method' => 'GET',
            'expected_status' => 200,
            'timeout_seconds' => 120,
            'data_path' => null,
        ])
        ->call('doMonitoring')
        ->assertNotified('API request failed');
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

test('edit check api action evaluates saved assertions for the current monitor', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Http::fake([
        'https://example.com/*' => Http::response(['data' => ['status' => 'degraded']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Saved Assertion API',
        'url' => 'https://example.com/saved-assertion-health',
        'http_method' => 'GET',
        'expected_status' => 200,
        'data_path' => 'data.status',
    ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'ok',
        'is_active' => true,
    ]);

    Livewire::test(EditMonitorApis::class, ['record' => $monitor->id])
        ->fillForm([
            'package_interval' => '5m',
        ])
        ->call('doMonitoring')
        ->assertHasNoFormErrors()
        ->assertNotified('Some API assertions failed');

    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://example.com/saved-assertion-health');
});

test('view page run now action queues a diagnostic run', function () {
    $this->createResourcePermissions('MonitorApis');
    Carbon::setTestNow('2026-04-24 12:00:00');

    $user = $this->actingAsSuperAdmin();

    Queue::fake();
    Http::preventStrayRequests();

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
        ->assertNotified('Diagnostic queued');

    Queue::assertPushed(RunApiMonitorDiagnosticJob::class, fn (RunApiMonitorDiagnosticJob $job): bool => $job->monitor->is($monitor));

    $monitor->refresh();

    expect($monitor->results()->count())->toBe(0)
        ->and($monitor->current_status)->toBeNull()
        ->and($monitor->last_heartbeat_at)->toBeNull()
        ->and($monitor->status_summary)->toBeNull()
        ->and($monitor->diagnostic_queued_at?->toDateTimeString())->toBe('2026-04-24 12:00:00');

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->assertSuccessful()
        ->assertSee('Latest Manual Run')
        ->assertSee('Manual Run Status')
        ->assertSee('Queued')
        ->assertSee('Queued At')
        ->assertSee('Apr 24, 2026');
});

test('view page run now action queues diagnostics for failure cases without moving live status', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    Queue::fake();

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

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->callAction('run_now')
        ->assertNotified('Diagnostic queued');

    Queue::assertPushed(RunApiMonitorDiagnosticJob::class, fn (RunApiMonitorDiagnosticJob $job): bool => $job->monitor->is($monitor));

    $monitor->refresh();

    expect($monitor->results()->count())->toBe(0)
        ->and($monitor->current_status)->toBe('healthy')
        ->and($monitor->last_heartbeat_at)->toBeNull()
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

test('view page hides run now action when api monitor is archived', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);
    $monitor->delete();

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->assertActionHidden('run_now');
});

test('view page disables run now action while api monitor diagnostic is queued', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'is_enabled' => true,
        'diagnostic_queued_at' => now(),
    ]);

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->assertActionDisabled('run_now');
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
        'status_summary' => 'API check failed with HTTP status 500.',
        'save_failed_response' => true,
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'status' => 'danger',
        'summary' => 'API check failed with HTTP status 500.',
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
        ->assertSee('API check failed with HTTP status 500.')
        ->assertSee('Expected HTTP status 200, got 500.')
        ->assertSee('[redacted]')
        ->assertSee('x-request-id')
        ->assertSee('trace-123');
});

test('api monitor view shows polling interval and due-state messaging', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $lastHeartbeatAt = \Illuminate\Support\Carbon::parse('2026-05-04 12:00:00');

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'package_interval' => '15m',
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'created_at' => $lastHeartbeatAt,
        'updated_at' => $lastHeartbeatAt,
    ]);

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->assertSuccessful()
        ->assertSee('Polling Interval')
        ->assertSee('Schedule State')
        ->assertSee('Next Scheduled Run')
        ->assertSee('15m')
        ->assertSee($lastHeartbeatAt->copy()->addMinutes(15)->toDayDateTimeString());
});

test('api monitor view uses latest real run evidence and still labels manual runs', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Checkout API',
        'current_status' => 'healthy',
        'status_summary' => 'Scheduler says the API is healthy.',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'status' => 'healthy',
        'summary' => 'Scheduled API check succeeded.',
        'http_code' => 200,
        'response_time_ms' => 120,
        'created_at' => now()->subMinutes(30),
    ]);

    MonitorApiResult::factory()->onDemand()->create([
        'monitor_api_id' => $monitor->id,
        'status' => 'danger',
        'summary' => 'Diagnostic API check failed.',
        'http_code' => 500,
        'response_time_ms' => 980,
        'created_at' => now()->subMinutes(2),
    ]);

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->assertSuccessful()
        ->assertSee('Latest Run Evidence')
        ->assertSee('Diagnostic API check failed.')
        ->assertSee('Latest Manual Run')
        ->assertDontSee('Scheduled API check succeeded.');

    expect($monitor->refresh()->latestScheduledResult->summary)->toBe('Scheduled API check succeeded.')
        ->and($monitor->latestDiagnosticResult->summary)->toBe('Diagnostic API check failed.');
});

test('api monitor view exposes latest scheduled failed assertion expected and actual values', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Checkout API',
        'current_status' => 'danger',
        'status_summary' => 'Scheduler says the API assertion failed.',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'status' => 'danger',
        'summary' => 'Scheduled API check failed with assertion evidence.',
        'http_code' => 200,
        'response_time_ms' => 860,
        'failed_assertions' => [[
            'path' => 'data.status',
            'type' => 'value_compare',
            'message' => 'Value comparison failed: expected = active',
            'actual' => 'pending',
            'expected' => '= active',
        ]],
    ]);

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->assertSuccessful()
        ->assertSee('Latest Run Evidence')
        ->assertSee('Scheduled API check failed with assertion evidence.')
        ->assertSee('Failed Assertions')
        ->assertSee('Value comparison failed: expected = active')
        ->assertSee('Expected')
        ->assertSee('= active')
        ->assertSee('Actual')
        ->assertSee('pending');
});

test('api monitor view exposes latest diagnostic evidence blocks', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Checkout API',
        'current_status' => 'healthy',
        'status_summary' => 'Scheduler says the API is healthy.',
    ]);

    MonitorApiResult::factory()->onDemand()->create([
        'monitor_api_id' => $monitor->id,
        'status' => 'danger',
        'summary' => 'Diagnostic API check failed with assertion evidence.',
        'http_code' => 200,
        'response_time_ms' => 860,
        'failed_assertions' => [[
            'path' => 'data.status',
            'type' => 'value_compare',
            'message' => 'Value comparison failed: expected = active',
            'actual' => 'pending',
            'expected' => '= active',
        ]],
        'request_headers' => [
            'Authorization' => '[redacted]',
            'X-Env' => 'staging',
        ],
        'response_headers' => [
            'content-type' => 'application/json',
            'x-request-id' => 'diagnostic-req-123',
        ],
        'response_body' => [
            'error' => 'invalid state',
            'trace_id' => 'diagnostic-trace-123',
        ],
    ]);

    Livewire::test(ViewMonitorApis::class, ['record' => $monitor->id])
        ->assertSuccessful()
        ->assertSee('Latest Manual Run')
        ->assertSee('Diagnostic API check failed with assertion evidence.')
        ->assertSee('Failed Assertions')
        ->assertSee('Value comparison failed: expected = active')
        ->assertSee('Expected')
        ->assertSee('= active')
        ->assertSee('Actual')
        ->assertSee('pending')
        ->assertSee('Request Headers Snapshot')
        ->assertSee('X-Env')
        ->assertSee('staging')
        ->assertSee('Response Headers Snapshot')
        ->assertSee('diagnostic-req-123')
        ->assertSee('Saved Failure Payload')
        ->assertSee('diagnostic-trace-123');
});

test('super admin can bulk disable api monitors', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitors = MonitorApis::factory()->count(3)->create([
        'created_by' => $user->id,
        'is_enabled' => true,
        'current_status' => 'warning',
        'status_summary' => 'API check returned warning.',
        'last_heartbeat_at' => now()->subMinutes(10),
        'stale_at' => now()->subMinute(),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('disable', $monitors);

    foreach ($monitors as $monitor) {
        expect($monitor->refresh()->is_enabled)->toBeFalse()
            ->and($monitor->current_status)->toBe('unknown')
            ->and($monitor->status_summary)->toBe('Disabled in Checkybot admin.')
            ->and($monitor->last_heartbeat_at)->toBeNull()
            ->and($monitor->stale_at)->toBeNull();
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

test('bulk change interval skips package managed api monitors', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $manualMonitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'source' => 'manual',
        'package_interval' => '5m',
    ]);
    $packageMonitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'source' => 'package',
        'package_name' => 'checkout-health',
        'package_interval' => '5m',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('changeInterval', collect([$manualMonitor, $packageMonitor]), data: [
            'interval' => '15m',
        ])
        ->assertNotified('1 API monitor updated, 1 package-managed monitor skipped');

    expect($manualMonitor->refresh()->package_interval)->toBe('15m')
        ->and($packageMonitor->refresh()->package_interval)->toBe('5m');
});

test('bulk change interval leaves package managed api monitors unchanged when all selected are package owned', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitors = MonitorApis::factory()->count(2)->create([
        'created_by' => $user->id,
        'source' => 'package',
        'package_interval' => '5m',
    ]);

    Livewire::test(ListMonitorApis::class)
        ->callTableBulkAction('changeInterval', $monitors, data: [
            'interval' => '15m',
        ])
        ->assertNotified('Nothing to update');

    foreach ($monitors as $monitor) {
        expect($monitor->refresh()->package_interval)->toBe('5m');
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
        'summary' => 'API check is degraded with HTTP status 404.',
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
        ->assertSee('API check is degraded with HTTP status 404.')
        ->assertSee('View Evidence')
        ->assertSee('1');
});

test('api monitor table latest evidence calls out retry-heavy timeout failures', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'title' => 'Booking Search API',
        'current_status' => 'danger',
    ]);

    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
        'status' => 'danger',
        'http_code' => 503,
        'failed_assertions' => null,
        'transport_error_type' => null,
        'retry_count' => 3,
        'elapsed_wall_time_ms' => 69536,
        'effective_timeout_seconds' => 30,
        'created_at' => now()->subMinutes(2),
    ]);

    Livewire::test(ListMonitorApis::class)
        ->assertSee('Booking Search API')
        ->assertSee('3 retries')
        ->assertSee('wall 69.5s')
        ->assertSee('timeout 30s');
});

test('api monitor results list can filter repeated failure causes', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
    ]);

    $transportFailure = MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
        'status' => 'danger',
        'summary' => 'DNS lookup failed.',
        'http_code' => 0,
        'failed_assertions' => null,
        'transport_error_type' => 'dns',
    ]);

    $assertionFailure = MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
        'status' => 'danger',
        'summary' => 'JSON assertion failed.',
        'http_code' => 200,
        'failed_assertions' => [[
            'path' => 'data.status',
            'type' => 'value_compare',
            'message' => 'Expected active, got pending.',
        ]],
    ]);

    $differentAssertionFailure = MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
        'status' => 'danger',
        'summary' => 'Parsed payload assertion failed.',
        'http_code' => 200,
        'failed_assertions' => [[
            'path' => 'data.parsed',
            'type' => 'exists',
            'message' => 'Expected parsed payload to exist.',
        ]],
    ]);

    $clientError = MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
        'status' => 'warning',
        'summary' => 'Endpoint returned 404.',
        'http_code' => 404,
        'failed_assertions' => null,
    ]);

    $serverError = MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
        'status' => 'danger',
        'summary' => 'Endpoint returned 503.',
        'http_code' => 503,
        'failed_assertions' => null,
    ]);

    Livewire::test(ResultsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => ViewMonitorApis::class,
    ])
        ->filterTable('transport_error_type', 'dns')
        ->assertCanSeeTableRecords([$transportFailure])
        ->assertCanNotSeeTableRecords([$assertionFailure, $clientError, $serverError])
        ->resetTableFilters()
        ->filterTable('assertion_failures', true)
        ->assertCanSeeTableRecords([$assertionFailure, $differentAssertionFailure])
        ->assertCanNotSeeTableRecords([$transportFailure, $clientError, $serverError])
        ->resetTableFilters()
        ->filterTable('assertion_path', ['path' => 'data.status'])
        ->assertCanSeeTableRecords([$assertionFailure])
        ->assertCanNotSeeTableRecords([$transportFailure, $differentAssertionFailure, $clientError, $serverError])
        ->resetTableFilters()
        ->filterTable('assertion_path', ['path' => 'data.parsed'])
        ->assertCanSeeTableRecords([$differentAssertionFailure])
        ->assertCanNotSeeTableRecords([$transportFailure, $assertionFailure, $clientError, $serverError])
        ->resetTableFilters()
        ->filterTable('no_response', true)
        ->assertCanSeeTableRecords([$transportFailure])
        ->assertCanNotSeeTableRecords([$assertionFailure, $differentAssertionFailure, $clientError, $serverError])
        ->resetTableFilters()
        ->filterTable('http_4xx', true)
        ->assertCanSeeTableRecords([$clientError])
        ->assertCanNotSeeTableRecords([$transportFailure, $assertionFailure, $differentAssertionFailure, $serverError])
        ->resetTableFilters()
        ->filterTable('http_5xx', true)
        ->assertCanSeeTableRecords([$serverError])
        ->assertCanNotSeeTableRecords([$transportFailure, $assertionFailure, $differentAssertionFailure, $clientError]);
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
        'summary' => 'API check failed with HTTP status 200.',
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

test('api monitor evidence infolist shows copyable redacted replay template', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'http_method' => 'POST',
        'url' => 'https://api.example.test/private?token=url-secret',
        'request_body_type' => 'json',
        'request_body' => '{"password":"body-secret","status":"active"}',
    ]);

    $result = MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'status' => 'danger',
        'summary' => 'API check failed.',
        'request_headers' => [
            'Authorization' => '[redacted]',
            'X-Api-Key' => '[redacted]',
            'Cookie' => '[redacted]',
            'Accept' => 'application/json',
        ],
    ]);

    $component = Livewire::test(ResultsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => ViewMonitorApis::class,
    ])
        ->mountTableAction('view', $result)
        ->assertHasNoTableActionErrors();

    $html = $component->getMountedActionModalHtml();

    expect($html)
        ->toContain('Replay Template')
        ->toContain('REPLACE_AUTHORIZATION')
        ->toContain('REPLACE_X_API_KEY')
        ->toContain('REPLACE_COOKIE')
        ->toContain('[redacted]')
        ->not->toContain('body-secret')
        ->not->toContain('url-secret');
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

test('api assertion form rejects invalid regex patterns before saving', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
    ]);

    Livewire::test(AssertionsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => EditMonitorApis::class,
    ])
        ->callTableAction('create', data: [
            'data_path' => 'data.status',
            'assertion_type' => 'regex_match',
            'regex_pattern' => '/[unterminated/',
            'is_active' => true,
            'sort_order' => 0,
        ])
        ->assertHasTableActionErrors(['regex_pattern']);

    expect($monitor->assertions()->count())->toBe(0);
});

test('api assertion form saves valid regex patterns', function () {
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $monitor = MonitorApis::factory()->create([
        'created_by' => $user->id,
    ]);

    Livewire::test(AssertionsRelationManager::class, [
        'ownerRecord' => $monitor,
        'pageClass' => EditMonitorApis::class,
    ])
        ->callTableAction('create', data: [
            'data_path' => 'data.status',
            'assertion_type' => 'regex_match',
            'regex_pattern' => '/^active|pending$/',
            'is_active' => true,
            'sort_order' => 0,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('monitor_api_assertions', [
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'regex_match',
        'regex_pattern' => '/^active|pending$/',
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
        'last_heartbeat_at' => now()->subMinutes(10),
        'stale_at' => now()->subMinute(),
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
        expect($monitor->refresh()->is_enabled)->toBeFalse()
            ->and($monitor->last_heartbeat_at)->toBeNull()
            ->and($monitor->stale_at)->toBeNull();
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

test('api monitor navigation badge excludes disabled monitors from unhealthy count', function () {
    $user = $this->actingAsSuperAdmin();
    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'warning',
        'is_enabled' => false,
    ]);
    MonitorApis::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
        'is_enabled' => true,
    ]);

    \App\Filament\Resources\MonitorApisResource::flushUnhealthyNavigationBadgeCache();

    expect(\App\Filament\Resources\MonitorApisResource::getNavigationBadge())->toBe('1/3')
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
    ])->not->toHaveKey('recently_recovered');
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
