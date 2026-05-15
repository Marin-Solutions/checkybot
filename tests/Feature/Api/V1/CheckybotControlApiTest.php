<?php

use App\Enums\RunSource;
use App\Jobs\LogUptimeSslJob;
use App\Jobs\RunApiMonitorDiagnosticJob;
use App\Models\ApiKey;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProjectComponentHeartbeat;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->apiKey = ApiKey::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Mimir',
    ]);
    $this->project = Project::factory()->create([
        'created_by' => $this->user->id,
        'package_key' => 'scrappa',
        'name' => 'Scrappa',
        'environment' => 'production',
        'base_url' => 'https://api.scrappa.test',
        'repository' => 'marin-solutions/scrappa',
    ]);
});

test('control api requires a valid api key and exposes me details', function () {
    $this->getJson('/api/v1/control/me')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Bearer API key is missing');

    $this->withToken('ck_invalid')
        ->getJson('/api/v1/control/me')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid or expired API key');

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/me')
        ->assertOk()
        ->assertJsonPath('data.authenticated', true)
        ->assertJsonPath('data.api_key.name', 'Mimir')
        ->assertJsonPath('data.user.id', $this->user->id)
        ->assertJsonStructure(['data' => ['server_time']]);
});

test('control api lists projects and package managed checks with compact status', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'url' => 'https://api.scrappa.test/health',
        'http_method' => 'GET',
        'request_path' => '/health',
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer secret-token',
        ],
        'request_body_type' => 'raw',
        'request_body' => '   ',
        'current_status' => 'danger',
        'status_summary' => 'API check failed with HTTP status 500.',
    ]);

    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $monitor->id,
        'summary' => 'API check failed with HTTP status 500.',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'scrappa')
        ->assertJsonPath('data.0.checks_count', 1)
        ->assertJsonPath('data.0.enabled_checks_count', 1)
        ->assertJsonPath('data.0.disabled_checks_count', 0)
        ->assertJsonPath('data.0.setup_verification.state', 'synced')
        ->assertJsonPath('data.0.setup_verification.label', 'Synced')
        ->assertJsonPath('data.0.setup_verification.tone', 'success')
        ->assertJsonPath('data.0.setup_verification.summary', 'Checkybot has received both the Laravel package registration and the first package sync payload for this application.')
        ->assertJsonPath('data.0.setup_verification.steps.1.status', 'complete');

    $response = $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/checks')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'search-health')
        ->assertJsonPath('data.0.status', 'danger')
        ->assertJsonPath('data.0.headers.Accept', 'application/json')
        ->assertJsonPath('data.0.headers.Authorization', '[redacted]')
        ->assertJsonPath('data.0.has_request_body', true);

    expect(json_encode($response->json()))->not->toContain('secret-token');
});

test('control api exposes setup verification states on project payloads', function () {
    $this->travelTo('2026-05-14 12:00:00');
    config()->set('monitor.package_sync_stale_minutes', 15);

    $waitingForRegistration = Project::factory()->create([
        'created_by' => $this->user->id,
        'name' => 'Waiting Registration',
        'environment' => 'production',
    ]);

    $waitingForFirstSync = Project::factory()->create([
        'created_by' => $this->user->id,
        'name' => 'Waiting Sync',
        'environment' => 'production',
        'identity_endpoint' => 'https://waiting-sync.test/checkybot/identity',
        'package_version' => '1.2.3',
    ]);

    $staleSync = Project::factory()->create([
        'created_by' => $this->user->id,
        'name' => 'Stale Sync',
        'environment' => 'production',
        'identity_endpoint' => 'https://stale-sync.test/checkybot/identity',
        'package_version' => '1.2.3',
        'package_key' => 'stale-sync',
        'base_url' => 'https://stale-sync.test',
        'last_synced_at' => now()->subMinutes(16),
    ]);

    $projects = collect($this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects')
        ->assertOk()
        ->json('data'))
        ->keyBy('id');

    expect($projects[$waitingForRegistration->id]['setup_verification']['state'])->toBe('waiting_for_registration')
        ->and($projects[$waitingForRegistration->id]['setup_verification']['steps'][0]['status'])->toBe('pending')
        ->and($projects[$waitingForFirstSync->id]['setup_verification']['state'])->toBe('waiting_for_first_sync')
        ->and($projects[$waitingForFirstSync->id]['setup_verification']['steps'][0]['status'])->toBe('complete')
        ->and($projects[$waitingForFirstSync->id]['setup_verification']['steps'][1]['status'])->toBe('pending')
        ->and($projects[$staleSync->id]['setup_verification']['state'])->toBe('sync_stale')
        ->and($projects[$staleSync->id]['setup_verification']['steps'][1]['status'])->toBe('stale')
        ->and($projects[$staleSync->id]['setup_verification']['summary'])->toContain('more than 15 minutes old')
        ->and($projects[$staleSync->id]['setup_verification']['action'])->toContain('php artisan checkybot:sync');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/control/projects/{$waitingForFirstSync->id}")
        ->assertOk()
        ->assertJsonPath('data.setup_verification.state', 'waiting_for_first_sync')
        ->assertJsonPath('data.setup_verification.steps.0.title', 'Laravel package registration')
        ->assertJsonPath('data.setup_verification.steps.1.title', 'First package sync');
});

test('control api upserts checks by stable key and redacts encrypted headers', function () {
    $payload = [
        'type' => 'api',
        'name' => 'Google Maps Search',
        'method' => 'GET',
        'url' => '/api/google-maps/search',
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer package-secret',
            'X-Api-Key' => 'scrappa-secret',
        ],
        'request_body_type' => 'json',
        'request_body' => [
            'email' => 'monitor@example.com',
            'password' => 'body-secret',
            'filters' => [],
        ],
        'expected_status' => 200,
        'timeout_seconds' => 15,
        'schedule' => 'every_5_minutes',
        'assertions' => [
            ['type' => 'json_path_exists', 'path' => '$.data'],
        ],
    ];

    $created = $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/google-maps-search', $payload)
        ->assertCreated()
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.check.key', 'google-maps-search')
        ->assertJsonPath('data.check.headers.Accept', 'application/json')
        ->assertJsonPath('data.check.headers.Authorization', '[redacted]')
        ->assertJsonPath('data.check.request_body_type', 'json')
        ->assertJsonPath('data.check.has_request_body', true);

    expect(json_encode($created->json()))->not->toContain('package-secret')
        ->and(json_encode($created->json()))->not->toContain('scrappa-secret')
        ->and(json_encode($created->json()))->not->toContain('body-secret');

    $updated = $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/google-maps-search', array_merge($payload, [
            'name' => 'Google Maps Search API',
            'timeout_seconds' => 20,
        ]))
        ->assertOk()
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.check.name', 'Google Maps Search API')
        ->assertJsonPath('data.check.timeout_seconds', 20);

    expect(DB::table('monitor_apis')->where('package_name', 'google-maps-search')->count())->toBe(1)
        ->and(json_encode($updated->json()))->not->toContain('package-secret');

    $rawHeaders = DB::table('monitor_apis')
        ->where('package_name', 'google-maps-search')
        ->value('headers');
    $storedInterval = DB::table('monitor_apis')
        ->where('package_name', 'google-maps-search')
        ->value('package_interval');
    $storedSchedule = DB::table('monitor_apis')
        ->where('package_name', 'google-maps-search')
        ->value('package_schedule');
    $rawRequestBody = DB::table('monitor_apis')
        ->where('package_name', 'google-maps-search')
        ->value('request_body');
    $storedRequestBody = MonitorApis::query()
        ->where('package_name', 'google-maps-search')
        ->value('request_body');

    expect($rawHeaders)->toContain('encrypted')
        ->and($rawHeaders)->not->toContain('package-secret')
        ->and($rawHeaders)->not->toContain('scrappa-secret');
    expect($storedInterval)->toBe('5m')
        ->and($storedSchedule)->toBe('every_5_minutes')
        ->and($rawRequestBody)->toContain('encrypted')
        ->and($rawRequestBody)->not->toContain('body-secret')
        ->and($storedRequestBody)->toBe('{"email":"monitor@example.com","password":"body-secret","filters":{}}');
});

test('control api resets api live health when target-defining settings change', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/search-health', [
            'name' => 'Search health',
            'method' => 'GET',
            'url' => '/api/search/health',
            'expected_status' => 200,
            'assertions' => [
                ['type' => 'json_path_exists', 'path' => '$.data'],
            ],
        ])
        ->assertCreated();

    $monitor = MonitorApis::query()
        ->where('package_name', 'search-health')
        ->sole();

    $monitor->forceFill([
        'current_status' => 'healthy',
        'status_summary' => 'Previous target was healthy.',
        'last_heartbeat_at' => now()->subMinutes(2),
        'stale_at' => now()->addMinutes(8),
        'diagnostic_queued_at' => now(),
    ])->save();

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/search-health', [
            'name' => 'Search health',
            'method' => 'POST',
            'url' => '/api/search/v2/health',
            'expected_status' => 201,
            'assertions' => [
                ['type' => 'json_path_exists', 'path' => '$.data.ready'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.check.status', 'unknown')
        ->assertJsonPath('data.check.status_summary', null)
        ->assertJsonPath('data.check.diagnostic_queued', false);

    $this->assertDatabaseHas('monitor_apis', [
        'id' => $monitor->id,
        'url' => 'https://api.scrappa.test/api/search/v2/health',
        'http_method' => 'POST',
        'expected_status' => 201,
        'current_status' => 'unknown',
        'status_summary' => null,
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'diagnostic_queued_at' => null,
    ]);

    expect($monitor->fresh()->awaiting_heartbeat_since)->not->toBeNull();
});

test('control api resets api live health when assertions change', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'url' => 'https://api.scrappa.test/api/search/health',
        'http_method' => 'GET',
        'request_path' => '/api/search/health',
        'expected_status' => 200,
        'is_enabled' => true,
        'current_status' => 'healthy',
        'status_summary' => 'Current assertions were healthy.',
        'last_heartbeat_at' => now()->subMinutes(2),
        'stale_at' => now()->addMinutes(8),
        'diagnostic_queued_at' => now(),
    ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.ready',
        'assertion_type' => 'exists',
        'comparison_operator' => null,
        'expected_value' => null,
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/search-health', [
            'name' => 'Search health',
            'method' => 'GET',
            'url' => '/api/search/health',
            'expected_status' => 200,
            'assertions' => [
                ['type' => 'json_path_exists', 'path' => '$.data.ok'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.check.status', 'unknown');

    $monitor->refresh();

    expect($monitor->current_status)->toBe('unknown')
        ->and($monitor->status_summary)->toBeNull()
        ->and($monitor->last_heartbeat_at)->toBeNull()
        ->and($monitor->stale_at)->toBeNull()
        ->and($monitor->diagnostic_queued_at)->toBeNull()
        ->and($monitor->awaiting_heartbeat_since)->not->toBeNull();
});

test('control api preserves api live health when null expected value assertions are unchanged', function () {
    $payload = [
        'name' => 'Search health',
        'method' => 'GET',
        'url' => '/api/search/health',
        'expected_status' => 200,
        'assertions' => [
            [
                'type' => 'json_path_equals',
                'path' => '$.data.deleted_at',
                'expected_value' => null,
            ],
        ],
    ];

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/search-health', $payload)
        ->assertCreated();

    $monitor = MonitorApis::query()
        ->where('package_name', 'search-health')
        ->sole();

    $monitor->forceFill([
        'current_status' => 'healthy',
        'status_summary' => 'Null assertion target is healthy.',
        'last_heartbeat_at' => now()->subMinutes(2),
        'awaiting_heartbeat_since' => null,
        'stale_at' => now()->addMinutes(8),
        'diagnostic_queued_at' => now(),
    ])->save();

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/search-health', $payload)
        ->assertOk()
        ->assertJsonPath('data.check.status', 'healthy')
        ->assertJsonPath('data.check.status_summary', 'Null assertion target is healthy.')
        ->assertJsonPath('data.check.diagnostic_queued', true);

    $monitor->refresh();

    expect($monitor->current_status)->toBe('healthy')
        ->and($monitor->status_summary)->toBe('Null assertion target is healthy.')
        ->and($monitor->last_heartbeat_at)->not->toBeNull()
        ->and($monitor->awaiting_heartbeat_since)->toBeNull()
        ->and($monitor->stale_at)->not->toBeNull()
        ->and($monitor->diagnostic_queued_at)->not->toBeNull()
        ->and($monitor->assertions()->sole()->expected_value)->toBeNull();
});

test('control api upserts package managed website uptime and ssl checks', function () {
    $created = $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/marketing-site', [
            'type' => 'website',
            'check_types' => ['uptime', 'ssl'],
            'name' => 'Marketing site',
            'url' => '/status',
            'schedule' => 'every_5_minutes',
        ])
        ->assertCreated()
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.check.key', 'marketing-site')
        ->assertJsonPath('data.check.type', 'website')
        ->assertJsonPath('data.check.check_types', ['uptime', 'ssl'])
        ->assertJsonPath('data.check.url', 'https://api.scrappa.test/status')
        ->assertJsonPath('data.check.schedule', '5m')
        ->assertJsonPath('data.check.enabled', true)
        ->assertJsonPath('data.check.status', 'unknown');

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'marketing-site',
        'name' => 'Marketing site',
        'url' => 'https://api.scrappa.test/status',
        'uptime_check' => true,
        'uptime_interval' => 5,
        'ssl_check' => true,
        'package_interval' => '5m',
    ]);

    $updated = $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/marketing-site', [
            'type' => 'website',
            'check_types' => ['ssl'],
            'name' => 'Marketing SSL',
            'url' => 'https://scrappa.test',
            'schedule' => '1d',
        ])
        ->assertOk()
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.check.name', 'Marketing SSL')
        ->assertJsonPath('data.check.check_types', ['ssl'])
        ->assertJsonPath('data.check.schedule', '1d');

    expect(DB::table('websites')->where('package_name', 'marketing-site')->count())->toBe(1)
        ->and($updated->json('data.check.url'))->toBe('https://scrappa.test');

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'marketing-site',
        'url' => 'https://scrappa.test',
        'uptime_check' => false,
        'uptime_interval' => null,
        'ssl_check' => true,
        'package_interval' => '1d',
    ]);
});

test('control api website upserts default to uptime and can disable the website check', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/homepage', [
            'type' => 'website',
            'name' => 'Homepage',
            'url' => 'https://scrappa.test',
        ])
        ->assertCreated()
        ->assertJsonPath('data.check.check_types', ['uptime'])
        ->assertJsonPath('data.check.schedule', '5m');

    Website::query()
        ->where('package_name', 'homepage')
        ->sole()
        ->forceFill([
            'current_status' => 'healthy',
            'status_summary' => 'Homepage is healthy.',
            'last_heartbeat_at' => now()->subMinutes(2),
            'awaiting_heartbeat_since' => null,
            'stale_at' => now()->addMinutes(8),
            'diagnostic_queued_at' => now(),
        ])
        ->save();

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/homepage', [
            'type' => 'website',
            'name' => 'Homepage',
            'url' => 'https://disabled.scrappa.test',
            'enabled' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.check.enabled', false)
        ->assertJsonPath('data.check.check_types', [])
        ->assertJsonPath('data.check.status_summary', 'Disabled by Checkybot control API.');

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'homepage',
        'url' => 'https://disabled.scrappa.test',
        'uptime_check' => false,
        'ssl_check' => false,
        'package_interval' => null,
        'current_status' => 'unknown',
        'status_summary' => 'Disabled by Checkybot control API.',
        'last_heartbeat_at' => null,
        'awaiting_heartbeat_since' => null,
        'stale_at' => null,
        'diagnostic_queued_at' => null,
    ]);
});

test('control api accepts http urls and package relative paths for check urls', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/absolute-health', [
            'name' => 'Absolute health',
            'url' => 'https://status.scrappa.test/health?source=checkybot',
        ])
        ->assertCreated()
        ->assertJsonPath('data.check.url', 'https://status.scrappa.test/health?source=checkybot');

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/relative-without-slash', [
            'name' => 'Relative without slash',
            'url' => 'api/health',
        ])
        ->assertCreated()
        ->assertJsonPath('data.check.url', 'https://api.scrappa.test/api/health');
});

test('control api rejects malformed and unsupported check urls', function (string $url) {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/malformed-url', [
            'name' => 'Malformed URL',
            'url' => $url,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    $this->assertDatabaseMissing('monitor_apis', [
        'package_name' => 'malformed-url',
    ]);
})->with([
    'blank path' => ['   '],
    'unsupported scheme' => ['ftp://api.scrappa.test/health'],
    'javascript scheme' => ['javascript:alert(1)'],
    'protocol relative url' => ['//api.scrappa.test/health'],
    'missing scheme separator' => ['https//api.scrappa.test/health'],
    'space in path' => ['/api/health check'],
    'fragment only' => ['#health'],
]);

test('control api normalizes surrounding whitespace before storing check urls', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/trimmed-absolute-url', [
            'name' => 'Trimmed absolute URL',
            'url' => ' https://status.scrappa.test/health ',
        ])
        ->assertCreated()
        ->assertJsonPath('data.check.url', 'https://status.scrappa.test/health');

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/trimmed-relative-url', [
            'name' => 'Trimmed relative URL',
            'url' => ' /health ',
        ])
        ->assertCreated()
        ->assertJsonPath('data.check.url', 'https://api.scrappa.test/health');

    expect(MonitorApis::query()
        ->whereIn('package_name', ['trimmed-absolute-url', 'trimmed-relative-url'])
        ->pluck('request_path', 'package_name')
        ->all())->toBe([
            'trimmed-absolute-url' => 'https://status.scrappa.test/health',
            'trimmed-relative-url' => '/health',
        ]);
});

test('control api rejects invalid regex assertion patterns', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/regex-health', [
            'name' => 'Regex health',
            'url' => '/health',
            'assertions' => [
                [
                    'type' => 'regex_match',
                    'path' => '$.status',
                    'regex_pattern' => '/[unterminated/',
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assertions.0.regex_pattern']);

    $this->assertDatabaseMissing('monitor_apis', [
        'package_name' => 'regex-health',
    ]);
});

test('control api rejects array expected values for comparison assertions', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/array-expected-value', [
            'name' => 'Array expected value',
            'url' => '/health',
            'assertions' => [
                [
                    'type' => 'value_compare',
                    'path' => '$.status',
                    'comparison_operator' => '=',
                    'expected_value' => ['ok'],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assertions.0.expected_value']);

    $this->assertDatabaseMissing('monitor_apis', [
        'package_name' => 'array-expected-value',
    ]);
});

test('control api defaults missing api schedules to the safe polling interval', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/search-health', [
            'name' => 'Search health',
            'url' => '/health',
        ])
        ->assertCreated()
        ->assertJsonPath('data.check.schedule', '5m');

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'search-health',
        'package_schedule' => '5m',
        'package_interval' => '5m',
    ]);
});

test('control api defaults blank api schedules to the safe polling interval', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/search-health', [
            'name' => 'Search health',
            'url' => '/health',
            'schedule' => '   ',
        ])
        ->assertCreated()
        ->assertJsonPath('data.check.schedule', '5m');

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'search-health',
        'package_schedule' => '5m',
        'package_interval' => '5m',
    ]);
});

test('control api preserves existing schedules when update payload omits schedule', function () {
    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'package_schedule' => '15m',
        'package_interval' => '15m',
    ]);

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/search-health', [
            'name' => 'Search health',
            'url' => '/health',
        ])
        ->assertOk()
        ->assertJsonPath('data.check.schedule', '15m');

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'search-health',
        'package_schedule' => '15m',
        'package_interval' => '15m',
    ]);
});

test('control api disables checks without deleting data', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'is_enabled' => true,
        'last_heartbeat_at' => now()->subMinutes(10),
        'stale_at' => now()->subMinute(),
        'diagnostic_queued_at' => now(),
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
    ]);

    $this->withToken($this->apiKey->key)
        ->patchJson('/api/v1/control/projects/scrappa/checks/search-health/disable')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.status', 'unknown');

    $this->assertDatabaseHas('monitor_apis', [
        'id' => $monitor->id,
        'is_enabled' => false,
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'diagnostic_queued_at' => null,
        'deleted_at' => null,
    ]);

    $this->assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
    ]);
});

test('control api disables listed website checks by package key', function () {
    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'marketing-site',
        'name' => 'Marketing site',
        'url' => 'https://scrappa.test',
        'uptime_check' => true,
        'ssl_check' => true,
        'package_interval' => '5m',
        'current_status' => 'danger',
        'status_summary' => 'Website DNS lookup failed.',
        'last_heartbeat_at' => now()->subMinutes(10),
        'stale_at' => now()->subMinute(),
        'diagnostic_queued_at' => now(),
    ]);

    WebsiteLogHistory::factory()->transportError('dns')->create([
        'website_id' => $website->id,
        'summary' => 'Website DNS lookup failed.',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/checks')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'marketing-site')
        ->assertJsonPath('data.0.type', 'website')
        ->assertJsonPath('data.0.enabled', true);

    $this->withToken($this->apiKey->key)
        ->patchJson('/api/v1/control/projects/scrappa/checks/marketing-site/disable')
        ->assertOk()
        ->assertJsonPath('data.key', 'marketing-site')
        ->assertJsonPath('data.type', 'website')
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.check_types', [])
        ->assertJsonPath('data.status', 'unknown')
        ->assertJsonPath('data.status_summary', 'Disabled by Checkybot control API.');

    $this->assertDatabaseHas('websites', [
        'id' => $website->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'diagnostic_queued_at' => null,
    ]);

    $this->assertDatabaseHas('website_log_history', [
        'website_id' => $website->id,
    ]);
});

test('control api disables listed component checks by name', function () {
    $component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'queue-worker',
        'summary' => 'Queue backlog is high.',
        'current_status' => 'danger',
        'last_reported_status' => 'danger',
        'last_heartbeat_at' => now()->subMinutes(10),
        'stale_detected_at' => now()->subMinute(),
        'is_stale' => true,
    ]);

    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $component->id,
        'component_name' => 'queue-worker',
        'status' => 'danger',
        'summary' => 'Queue backlog is high.',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/checks')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'queue-worker')
        ->assertJsonPath('data.0.type', 'component')
        ->assertJsonPath('data.0.enabled', true);

    $this->withToken($this->apiKey->key)
        ->patchJson('/api/v1/control/projects/scrappa/checks/queue-worker/disable?type=component')
        ->assertOk()
        ->assertJsonPath('data.key', 'queue-worker')
        ->assertJsonPath('data.type', 'component')
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.status', 'unknown')
        ->assertJsonPath('data.status_summary', 'Disabled by Checkybot control API.')
        ->assertJsonPath('data.delivery_state', 'archived')
        ->assertJsonPath('data.is_archived', true);

    $this->assertDatabaseHas('project_components', [
        'id' => $component->id,
        'is_archived' => true,
        'project_paused_monitoring' => false,
        'archive_reason' => ProjectComponent::ARCHIVE_REASON_USER,
        'current_status' => 'unknown',
        'last_reported_status' => 'unknown',
        'summary' => 'Disabled by Checkybot control API.',
        'last_heartbeat_at' => null,
        'stale_detected_at' => null,
        'is_stale' => false,
    ]);

    $this->assertDatabaseHas('project_component_heartbeats', [
        'project_component_id' => $component->id,
        'component_name' => 'queue-worker',
    ]);
});

test('control api requires type when disabling an ambiguous check key', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'is_enabled' => true,
    ]);

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'uptime_check' => true,
        'ssl_check' => true,
    ]);

    $this->withToken($this->apiKey->key)
        ->patchJson('/api/v1/control/projects/scrappa/checks/shared-health/disable')
        ->assertConflict()
        ->assertJsonPath('message', 'Check key matches multiple check types. Pass type=api, type=website, or type=component to disable a specific check.');

    $this->withToken($this->apiKey->key)
        ->patchJson('/api/v1/control/projects/scrappa/checks/shared-health/disable?type=website')
        ->assertOk()
        ->assertJsonPath('data.type', 'website')
        ->assertJsonPath('data.enabled', false);

    expect($monitor->refresh()->is_enabled)->toBeTrue()
        ->and($website->refresh()->uptime_check)->toBeFalse()
        ->and($website->ssl_check)->toBeFalse();
});

test('control api requires type when triggering an ambiguous runnable check key', function () {
    Queue::fake();

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'is_enabled' => true,
    ]);

    Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'uptime_check' => true,
        'ssl_check' => true,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/shared-health/runs')
        ->assertConflict()
        ->assertJsonPath('message', 'Check key matches multiple runnable check types. Pass type=api or type=website to trigger a specific check run.');

    Queue::assertNotPushed(LogUptimeSslJob::class);
});

test('control api accepts type to trigger the intended check surface for ambiguous run keys', function () {
    Queue::fake();
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'title' => 'Shared API health',
        'url' => 'https://api.scrappa.test/shared-health',
        'is_enabled' => true,
    ]);

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'name' => 'Shared website health',
        'url' => 'https://scrappa.test',
        'uptime_check' => true,
        'ssl_check' => true,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/shared-health/runs?type=api')
        ->assertOk()
        ->assertJsonPath('data.check.type', 'api')
        ->assertJsonPath('data.check.key', 'shared-health')
        ->assertJsonPath('data.result.run_source', RunSource::OnDemand->value);

    $this->assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
        'run_source' => RunSource::OnDemand->value,
    ]);

    Queue::assertNotPushed(LogUptimeSslJob::class);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/shared-health/runs?type=website')
        ->assertAccepted()
        ->assertJsonPath('data.check.type', 'website')
        ->assertJsonPath('data.check.key', 'shared-health')
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(LogUptimeSslJob::class, function (LogUptimeSslJob $job): bool {
        return $job->website->package_name === 'shared-health'
            && $job->onDemand === true;
    });

    expect($website->refresh()->diagnostic_queued_at)->not->toBeNull();
});

test('control api queues listed website checks by package key', function () {
    Queue::fake();
    $this->travelTo(now()->setTime(12, 15, 0));

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'marketing-site',
        'name' => 'Marketing site',
        'url' => 'https://scrappa.test',
        'uptime_check' => true,
        'ssl_check' => true,
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(5),
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/marketing-site/runs')
        ->assertAccepted()
        ->assertJsonPath('message', 'Check run queued.')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.queued', true)
        ->assertJsonPath('data.run_source', RunSource::OnDemand->value)
        ->assertJsonPath('data.check.key', 'marketing-site')
        ->assertJsonPath('data.check.type', 'website')
        ->assertJsonPath('data.check.enabled', true)
        ->assertJsonPath('data.result', null);

    $website->refresh();

    expect($website->diagnostic_queued_at?->toDateTimeString())->toBe(now()->toDateTimeString());

    Queue::assertPushed(LogUptimeSslJob::class, function (LogUptimeSslJob $job): bool {
        return $job->website->package_name === 'marketing-site'
            && $job->onDemand === true
            && $job->diagnosticRunId !== '';
    });

    $this->assertDatabaseMissing('website_log_history', [
        'website_id' => $website->id,
    ]);
});

test('control api reports already queued website diagnostic runs without dispatching another job', function () {
    Queue::fake();

    Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'marketing-site',
        'name' => 'Marketing site',
        'url' => 'https://scrappa.test',
        'uptime_check' => true,
        'ssl_check' => false,
        'diagnostic_queued_at' => now(),
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/marketing-site/runs')
        ->assertAccepted()
        ->assertJsonPath('message', 'Check run queued.')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.queued', false)
        ->assertJsonPath('data.check.key', 'marketing-site');

    Queue::assertNotPushed(LogUptimeSslJob::class);
});

test('control api rejects disabled website diagnostic runs', function () {
    Queue::fake();

    Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'paused-site',
        'name' => 'Paused site',
        'url' => 'https://paused.scrappa.test',
        'uptime_check' => false,
        'ssl_check' => false,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/paused-site/runs')
        ->assertConflict()
        ->assertJsonPath('message', 'Check is disabled. Enable uptime or SSL checks before triggering a run.');

    Queue::assertNotPushed(LogUptimeSslJob::class);
});

test('control api triggers a diagnostic check run without moving live status or latest failures', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 500),
    ]);

    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'url' => 'https://api.scrappa.test/health',
        'data_path' => 'data.status',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(10),
        'is_enabled' => true,
    ]);

    $heartbeatBefore = $monitor->last_heartbeat_at;

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/search-health/runs')
        ->assertOk()
        ->assertJsonPath('data.check.key', 'search-health')
        ->assertJsonPath('data.result.success', false)
        ->assertJsonPath('data.result.status', 'danger')
        ->assertJsonPath('data.result.run_source', RunSource::OnDemand->value)
        ->assertJsonPath('data.result.is_on_demand', true);

    $monitor->refresh();

    expect($monitor->current_status)->toBe('healthy')
        ->and($monitor->last_heartbeat_at?->equalTo($heartbeatBefore))->toBeTrue();

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/failures?project=scrappa')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('control api excludes diagnostic API rows from latest failures', function () {
    $scheduledMonitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'scheduled-health',
        'title' => 'Scheduled health',
        'current_status' => 'danger',
    ]);
    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $scheduledMonitor->id,
        'summary' => 'Scheduled failure',
        'created_at' => now()->subMinutes(2),
    ]);

    $diagnosticMonitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'diagnostic-health',
        'title' => 'Diagnostic health',
    ]);
    MonitorApiResult::factory()->failed()->onDemand()->create([
        'monitor_api_id' => $diagnosticMonitor->id,
        'summary' => 'Diagnostic failure',
        'created_at' => now()->subMinute(),
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/failures?project=scrappa')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.check.key', 'scheduled-health')
        ->assertJsonPath('data.0.summary', 'Scheduled failure');

    expect(json_encode($response->json()))->not->toContain('Diagnostic failure');
});

test('control api latest failures only returns currently failing latest scheduled results', function () {
    $recoveredMonitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'recovered-health',
        'title' => 'Recovered health',
        'current_status' => 'healthy',
    ]);
    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $recoveredMonitor->id,
        'summary' => 'Historical failure that has recovered.',
        'created_at' => now()->subMinutes(10),
    ]);
    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $recoveredMonitor->id,
        'summary' => 'Recovered successfully.',
        'created_at' => now()->subMinutes(5),
    ]);

    $stillFailingMonitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'current-health',
        'title' => 'Current health',
        'current_status' => 'danger',
    ]);
    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $stillFailingMonitor->id,
        'summary' => 'Current scheduled failure.',
        'created_at' => now()->subMinutes(2),
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/failures?project=scrappa')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.check.key', 'current-health')
        ->assertJsonPath('data.0.summary', 'Current scheduled failure.');

    expect(json_encode($response->json()))->not->toContain('Historical failure that has recovered');
});

test('control api latest failures includes website and component failures', function () {
    $apiMonitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'current_status' => 'danger',
    ]);
    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $apiMonitor->id,
        'summary' => 'API is down.',
        'created_at' => now()->subMinutes(3),
    ]);

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'Marketing site',
        'url' => 'https://scrappa.test',
        'source' => 'package',
        'package_name' => 'marketing-uptime',
        'current_status' => 'danger',
        'uptime_check' => true,
        'ssl_check' => true,
    ]);
    WebsiteLogHistory::factory()->transportError('dns')->create([
        'website_id' => $website->id,
        'summary' => 'Website DNS lookup failed.',
        'created_at' => now()->subMinutes(2),
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'queue-worker',
        'current_status' => 'warning',
        'is_archived' => false,
    ]);
    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $component->id,
        'component_name' => 'queue-worker',
        'status' => 'warning',
        'event' => 'heartbeat',
        'summary' => 'Queue latency is above threshold.',
        'metrics' => ['latency_seconds' => 91],
        'observed_at' => now()->subMinute(),
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/failures?project=scrappa')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.check.type', 'component')
        ->assertJsonPath('data.0.check.key', 'queue-worker')
        ->assertJsonPath('data.0.metrics.latency_seconds', 91)
        ->assertJsonPath('data.1.check.type', 'website')
        ->assertJsonPath('data.1.check.key', 'marketing-uptime')
        ->assertJsonPath('data.1.transport_error_type', 'dns')
        ->assertJsonPath('data.2.check.key', 'api-health');
});

test('control api latest failures excludes recovered website and component rows', function () {
    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'Recovered website',
        'source' => 'package',
        'package_name' => 'recovered-website',
        'current_status' => 'healthy',
        'uptime_check' => true,
    ]);
    WebsiteLogHistory::factory()->transportError('timeout')->create([
        'website_id' => $website->id,
        'summary' => 'Historical website failure.',
        'created_at' => now()->subMinutes(10),
    ]);
    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'summary' => 'Website recovered.',
        'status' => 'healthy',
        'created_at' => now()->subMinutes(5),
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'scheduler',
        'current_status' => 'healthy',
        'is_archived' => false,
    ]);
    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $component->id,
        'component_name' => 'scheduler',
        'status' => 'danger',
        'summary' => 'Historical component failure.',
        'observed_at' => now()->subMinutes(10),
    ]);
    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $component->id,
        'component_name' => 'scheduler',
        'status' => 'healthy',
        'summary' => 'Component recovered.',
        'observed_at' => now()->subMinutes(5),
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/failures?project=scrappa')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect(json_encode($response->json()))->not->toContain('Historical website failure')
        ->and(json_encode($response->json()))->not->toContain('Historical component failure');
});

test('control api diagnostic check run does not send failure notifications', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 200),
    ]);
    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'url' => 'https://api.scrappa.test/health',
        'data_path' => 'data.status',
        'current_status' => 'healthy',
        'is_enabled' => true,
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $this->user->id,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/search-health/runs')
        ->assertOk()
        ->assertJsonPath('data.result.status', 'warning');

    $monitor->refresh();

    expect($monitor->current_status)->toBe('healthy');

    Mail::assertNothingSent();
});

test('control api diagnostic check run does not send recovery notifications', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);
    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'url' => 'https://api.scrappa.test/health',
        'data_path' => 'data.status',
        'current_status' => 'danger',
        'is_enabled' => true,
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $this->user->id,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/search-health/runs')
        ->assertOk()
        ->assertJsonPath('data.result.status', 'healthy');

    $monitor->refresh();

    expect($monitor->current_status)->toBe('danger');

    Mail::assertNothingSent();
});

test('control api returns project detail and recent runs', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'current_status' => 'warning',
        'is_enabled' => true,
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'status' => 'warning',
        'summary' => 'API check is degraded with HTTP status 404.',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa')
        ->assertOk()
        ->assertJsonPath('data.key', 'scrappa')
        ->assertJsonPath('data.checks_count', 1)
        ->assertJsonPath('data.enabled_checks_count', 1)
        ->assertJsonPath('data.disabled_checks_count', 0)
        ->assertJsonPath('data.status_counts.warning', 1)
        ->assertJsonPath('data.latest_failure.check.key', 'search-health');

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'landing-page',
        'name' => 'Landing page',
        'url' => 'https://scrappa.test',
        'uptime_check' => true,
        'ssl_check' => true,
    ]);

    WebsiteLogHistory::factory()->onDemand()->create([
        'website_id' => $website->id,
        'status' => 'healthy',
        'summary' => 'Website diagnostic completed.',
        'created_at' => now()->addMinute(),
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'queue-worker',
        'current_status' => 'danger',
        'last_reported_status' => 'danger',
        'is_archived' => false,
    ]);
    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $component->id,
        'component_name' => 'queue-worker',
        'status' => 'danger',
        'event' => 'heartbeat',
        'summary' => 'Queue worker heartbeat failed.',
        'metrics' => ['failed_jobs' => 12],
        'observed_at' => now()->addMinutes(2),
        'created_at' => now()->subMinute(),
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/runs?project=scrappa')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.check.key', 'queue-worker')
        ->assertJsonPath('data.0.check.type', 'component')
        ->assertJsonPath('data.0.summary', 'Queue worker heartbeat failed.')
        ->assertJsonPath('data.0.metrics.failed_jobs', 12)
        ->assertJsonPath('data.0.run_source', 'heartbeat')
        ->assertJsonPath('data.1.check.key', 'landing-page')
        ->assertJsonPath('data.1.check.type', 'website')
        ->assertJsonPath('data.1.summary', 'Website diagnostic completed.')
        ->assertJsonPath('data.2.check.key', 'search-health')
        ->assertJsonPath('data.2.check.type', 'api')
        ->assertJsonPath('data.2.status', 'warning');

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/runs')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.check.key', 'queue-worker')
        ->assertJsonPath('data.1.check.key', 'landing-page')
        ->assertJsonPath('data.2.check.key', 'search-health');
});

test('control api result payloads include safe api failure evidence', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'current_status' => 'danger',
    ]);

    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $monitor->id,
        'http_code' => 0,
        'summary' => 'API check failed because DNS lookup failed.',
        'transport_error_type' => 'dns',
        'transport_error_message' => 'Could not resolve https://user:transport-secret@api.scrappa.test/private/request-secret?debug=transport-query-secret, with Bearer transport-bearer-secret',
        'transport_error_code' => 6,
        'request_headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer request-secret',
            'Proxy-Authorization' => 'Basic proxy-secret',
            'X-Api-Key' => 'package-secret',
        ],
        'response_headers' => [
            'content-type' => 'application/json',
            'set-cookie' => 'session=response-secret',
            'x-request-id' => 'req-123',
        ],
        'response_body' => [
            'error' => 'upstream unavailable',
            'trace_id' => 'trace-123',
            'author' => 'Scrappa worker',
            'authenticated_at' => '2026-04-29T06:00:00Z',
            MonitorApiResult::RAW_BODY_KEY => 'Token expired. Your token was: raw-body-secret',
            MonitorApiResult::ERROR_METADATA_KEY => 'cURL error included error-metadata-secret',
            'raw_body' => 'Token expired. Your token was: legacy-raw-secret',
            'access_token' => 'body-token-secret',
            'nested' => [
                'password' => 'body-password-secret',
                'detail' => 'resolver timeout',
            ],
        ],
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/failures?project=scrappa')
        ->assertOk()
        ->assertJsonPath('data.0.check.key', 'search-health')
        ->assertJsonPath('data.0.transport_error_type', 'dns')
        ->assertJsonPath('data.0.transport_error_message', 'Could not resolve https://api.scrappa.test/[redacted-url], with Bearer [redacted]')
        ->assertJsonPath('data.0.transport_error_code', 6)
        ->assertJsonPath('data.0.request_headers.Accept', 'application/json')
        ->assertJsonPath('data.0.request_headers.Authorization', '[redacted]')
        ->assertJsonPath('data.0.request_headers.Proxy-Authorization', '[redacted]')
        ->assertJsonPath('data.0.request_headers.X-Api-Key', '[redacted]')
        ->assertJsonPath('data.0.response_headers.content-type', 'application/json')
        ->assertJsonPath('data.0.response_headers.set-cookie', '[redacted]')
        ->assertJsonPath('data.0.response_headers.x-request-id', 'req-123')
        ->assertJsonPath('data.0.response_body.error', 'upstream unavailable')
        ->assertJsonPath('data.0.response_body.trace_id', 'trace-123')
        ->assertJsonPath('data.0.response_body.author', 'Scrappa worker')
        ->assertJsonPath('data.0.response_body.authenticated_at', '2026-04-29T06:00:00Z')
        ->assertJsonPath('data.0.response_body.'.MonitorApiResult::RAW_BODY_KEY, '[redacted]')
        ->assertJsonPath('data.0.response_body.'.MonitorApiResult::ERROR_METADATA_KEY, '[redacted]')
        ->assertJsonPath('data.0.response_body.raw_body', '[redacted]')
        ->assertJsonPath('data.0.response_body.access_token', '[redacted]')
        ->assertJsonPath('data.0.response_body.nested.password', '[redacted]')
        ->assertJsonPath('data.0.response_body.nested.detail', 'resolver timeout');

    expect(json_encode($response->json()))->not->toContain('request-secret')
        ->and(json_encode($response->json()))->not->toContain('proxy-secret')
        ->and(json_encode($response->json()))->not->toContain('package-secret')
        ->and(json_encode($response->json()))->not->toContain('response-secret')
        ->and(json_encode($response->json()))->not->toContain('transport-secret')
        ->and(json_encode($response->json()))->not->toContain('transport-query-secret')
        ->and(json_encode($response->json()))->not->toContain('transport-bearer-secret')
        ->and(json_encode($response->json()))->not->toContain('raw-body-secret')
        ->and(json_encode($response->json()))->not->toContain('error-metadata-secret')
        ->and(json_encode($response->json()))->not->toContain('legacy-raw-secret')
        ->and(json_encode($response->json()))->not->toContain('body-token-secret')
        ->and(json_encode($response->json()))->not->toContain('body-password-secret');
});

test('control api redacts top-level raw response body strings', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'current_status' => 'danger',
    ]);

    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $monitor->id,
        'summary' => 'API check failed with a raw upstream body.',
        'response_body' => 'Token expired. Your token was: top-level-body-secret',
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/failures?project=scrappa')
        ->assertOk()
        ->assertJsonPath('data.0.response_body', '[redacted]');

    expect(json_encode($response->json()))->not->toContain('top-level-body-secret');
});

test('control api result evidence preserves null bodies and truncates long strings', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'current_status' => 'danger',
    ]);

    $longValue = str_repeat('x', 4200);

    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $monitor->id,
        'summary' => 'API check failed with a very large debug payload.',
        'transport_error_message' => $longValue,
        'request_headers' => [
            'x-debug-trace' => $longValue,
        ],
        'response_body' => null,
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/failures?project=scrappa')
        ->assertOk()
        ->assertJsonPath('data.0.response_body', null);

    expect($response->json('data.0.transport_error_message'))->toEndWith('... [truncated]')
        ->and($response->json('data.0.transport_error_message'))->not->toBe($longValue)
        ->and($response->json('data.0.request_headers.x-debug-trace'))->toEndWith('... [truncated]')
        ->and($response->json('data.0.request_headers.x-debug-trace'))->not->toBe($longValue);
});

test('control api project status counts exclude disabled checks and report them separately', function () {
    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'healthy-api',
        'current_status' => 'healthy',
        'is_enabled' => true,
    ]);

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'stale-api',
        'current_status' => 'unknown',
        'is_enabled' => true,
    ]);

    MonitorApis::factory()->disabled()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'disabled-api',
        'current_status' => 'unknown',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects')
        ->assertOk()
        ->assertJsonPath('data.0.checks_count', 3)
        ->assertJsonPath('data.0.enabled_checks_count', 2)
        ->assertJsonPath('data.0.disabled_checks_count', 1);

    $response = $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa')
        ->assertOk()
        ->assertJsonPath('data.checks_count', 3)
        ->assertJsonPath('data.enabled_checks_count', 2)
        ->assertJsonPath('data.disabled_checks_count', 1)
        ->assertJsonPath('data.status_counts.healthy', 1)
        ->assertJsonPath('data.status_counts.unknown', 1)
        ->assertJsonPath('data.status_counts.disabled', 1);

    expect($response->json('data.status_counts'))->not->toHaveKey('danger');
});

test('control api project status counts treat stale package api and website checks as danger', function () {
    $this->travelTo('2026-05-14 12:00:00');

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'fresh-api',
        'current_status' => 'healthy',
        'is_enabled' => true,
        'last_heartbeat_at' => now()->subMinute(),
        'package_interval' => '5m',
        'stale_at' => null,
    ]);

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'stale-api',
        'current_status' => 'healthy',
        'is_enabled' => true,
        'last_heartbeat_at' => now()->subMinute(),
        'package_interval' => '5m',
        'stale_at' => now()->subMinute(),
    ]);

    Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'stale-homepage',
        'current_status' => 'healthy',
        'uptime_check' => true,
        'ssl_check' => false,
        'last_heartbeat_at' => now()->subMinutes(10),
        'package_interval' => '5m',
        'stale_at' => null,
    ]);

    Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'disabled-homepage',
        'current_status' => 'healthy',
        'uptime_check' => false,
        'ssl_check' => false,
        'last_heartbeat_at' => now()->subMinutes(10),
        'package_interval' => '5m',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa')
        ->assertOk()
        ->assertJsonPath('data.status_counts.healthy', 1)
        ->assertJsonPath('data.status_counts.danger', 2)
        ->assertJsonPath('data.status_counts.disabled', 1);
});

test('control api project summaries include package managed website checks', function () {
    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'current_status' => 'healthy',
        'is_enabled' => true,
    ]);

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'landing-page',
        'name' => 'Landing page',
        'url' => 'https://scrappa.test',
        'current_status' => 'danger',
        'status_summary' => 'Website DNS lookup failed.',
        'uptime_check' => true,
        'ssl_check' => true,
        'package_interval' => '5m',
    ]);

    WebsiteLogHistory::factory()->transportError('dns')->create([
        'website_id' => $website->id,
        'summary' => 'Website DNS lookup failed.',
    ]);

    Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'removed-landing-page',
        'name' => 'Removed landing page',
        'uptime_check' => false,
        'ssl_check' => false,
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects')
        ->assertOk()
        ->assertJsonPath('data.0.checks_count', 3)
        ->assertJsonPath('data.0.enabled_checks_count', 2)
        ->assertJsonPath('data.0.disabled_checks_count', 1);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa')
        ->assertOk()
        ->assertJsonPath('data.checks_count', 3)
        ->assertJsonPath('data.enabled_checks_count', 2)
        ->assertJsonPath('data.disabled_checks_count', 1)
        ->assertJsonPath('data.status_counts.healthy', 1)
        ->assertJsonPath('data.status_counts.danger', 1)
        ->assertJsonPath('data.status_counts.disabled', 1)
        ->assertJsonPath('data.latest_failure.check.key', 'landing-page');

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/checks')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'api-health')
        ->assertJsonPath('data.0.type', 'api')
        ->assertJsonPath('data.0.supports_run', true)
        ->assertJsonPath('data.1.key', 'landing-page')
        ->assertJsonPath('data.1.type', 'website')
        ->assertJsonPath('data.1.check_types', ['uptime', 'ssl'])
        ->assertJsonPath('data.1.enabled', true)
        ->assertJsonPath('data.1.supports_run', true)
        ->assertJsonPath('data.1.status', 'danger')
        ->assertJsonPath('data.1.latest_result.transport_error_type', 'dns')
        ->assertJsonPath('data.2.key', 'removed-landing-page')
        ->assertJsonPath('data.2.type', 'website')
        ->assertJsonPath('data.2.check_types', [])
        ->assertJsonPath('data.2.enabled', false);
});

test('control api returns latest result for each listed check', function () {
    $alpha = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'alpha-health',
        'title' => 'Alpha health',
    ]);
    $beta = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'beta-health',
        'title' => 'Beta health',
    ]);

    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $alpha->id,
        'created_at' => now()->subMinutes(2),
    ]);
    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $alpha->id,
        'status' => 'danger',
        'created_at' => now(),
    ]);
    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $beta->id,
        'status' => 'healthy',
        'created_at' => now()->subMinute(),
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/checks')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'alpha-health')
        ->assertJsonPath('data.0.latest_result.status', 'danger')
        ->assertJsonPath('data.1.key', 'beta-health')
        ->assertJsonPath('data.1.latest_result.status', 'healthy');
});

test('control api lists queued diagnostic state and latest diagnostic evidence for runnable checks', function () {
    $this->travelTo(now()->setTime(14, 15, 0));

    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'diagnostic_queued_at' => now(),
    ]);

    MonitorApiResult::factory()->onDemand()->failed()->create([
        'monitor_api_id' => $api->id,
        'summary' => 'Diagnostic API run failed.',
        'created_at' => now()->subMinute(),
    ]);

    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $api->id,
        'summary' => 'Scheduled API run is healthy.',
        'created_at' => now()->subSeconds(30),
    ]);

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'homepage',
        'name' => 'Homepage',
        'uptime_check' => true,
        'ssl_check' => true,
        'diagnostic_queued_at' => now()->subMinutes(5),
    ]);

    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'summary' => 'Scheduled website run is healthy.',
        'created_at' => now()->subMinutes(10),
    ]);

    WebsiteLogHistory::factory()->onDemand()->transportError('timeout')->create([
        'website_id' => $website->id,
        'summary' => 'Diagnostic website run timed out.',
        'created_at' => now(),
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/checks')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'api-health')
        ->assertJsonPath('data.0.diagnostic_queued', true)
        ->assertJsonPath('data.0.diagnostic_queued_at', now()->toISOString())
        ->assertJsonPath('data.0.latest_result.summary', 'Scheduled API run is healthy.')
        ->assertJsonPath('data.0.latest_diagnostic_result.summary', 'Diagnostic API run failed.')
        ->assertJsonPath('data.0.latest_diagnostic_result.run_source', RunSource::OnDemand->value)
        ->assertJsonPath('data.1.key', 'homepage')
        ->assertJsonPath('data.1.diagnostic_queued', false)
        ->assertJsonPath('data.1.diagnostic_queued_at', now()->subMinutes(5)->toISOString())
        ->assertJsonPath('data.1.latest_result.summary', 'Diagnostic website run timed out.')
        ->assertJsonPath('data.1.latest_diagnostic_result.summary', 'Diagnostic website run timed out.')
        ->assertJsonPath('data.1.latest_diagnostic_result.transport_error_type', 'timeout');
});

test('control api lists component checks with delivery state and latest heartbeat evidence', function () {
    $this->travelTo(now()->setTime(9, 30, 0));

    $component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'queue-worker',
        'summary' => 'Queue latency is above threshold.',
        'declared_interval' => '2m',
        'interval_minutes' => 2,
        'current_status' => 'healthy',
        'last_reported_status' => 'warning',
        'metrics' => ['latency_seconds' => 91],
        'last_heartbeat_at' => now()->subMinutes(7),
        'stale_detected_at' => now()->subMinute(),
        'is_stale' => true,
        'is_archived' => false,
    ]);

    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $component->id,
        'component_name' => 'queue-worker',
        'status' => 'healthy',
        'event' => 'heartbeat',
        'summary' => 'Previous heartbeat was healthy.',
        'metrics' => ['latency_seconds' => 12],
        'observed_at' => now()->subMinutes(10),
    ]);

    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $component->id,
        'component_name' => 'queue-worker',
        'status' => 'warning',
        'event' => 'stale',
        'summary' => 'Queue worker heartbeat is stale.',
        'metrics' => ['latency_seconds' => 91],
        'observed_at' => now()->subMinute(),
    ]);

    ProjectComponent::factory()->archived()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'retired-worker',
        'summary' => 'Removed from package configuration.',
        'last_heartbeat_at' => null,
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/checks')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.key', 'queue-worker')
        ->assertJsonPath('data.0.type', 'component')
        ->assertJsonPath('data.0.enabled', true)
        ->assertJsonPath('data.0.supports_run', false)
        ->assertJsonPath('data.0.status', 'danger')
        ->assertJsonPath('data.0.reported_status', 'warning')
        ->assertJsonPath('data.0.delivery_state', 'stale')
        ->assertJsonPath('data.0.delivery_state_label', 'Stale')
        ->assertJsonPath('data.0.declared_interval', '2m')
        ->assertJsonPath('data.0.interval_minutes', 2)
        ->assertJsonPath('data.0.last_heartbeat_at', now()->subMinutes(7)->toISOString())
        ->assertJsonPath('data.0.stale_at', now()->subMinute()->toISOString())
        ->assertJsonPath('data.0.stale_threshold_at', now()->subMinutes(4)->toISOString())
        ->assertJsonPath('data.0.silenced_until', null)
        ->assertJsonPath('data.0.is_stale', true)
        ->assertJsonPath('data.0.metrics.latency_seconds', 91)
        ->assertJsonPath('data.0.latest_result.check.type', 'component')
        ->assertJsonPath('data.0.latest_result.status', 'warning')
        ->assertJsonPath('data.0.latest_result.event', 'stale')
        ->assertJsonPath('data.0.latest_result.summary', 'Queue worker heartbeat is stale.')
        ->assertJsonPath('data.0.latest_result.metrics.latency_seconds', 91)
        ->assertJsonPath('data.1.key', 'retired-worker')
        ->assertJsonPath('data.1.type', 'component')
        ->assertJsonPath('data.1.enabled', false)
        ->assertJsonPath('data.1.delivery_state', 'archived')
        ->assertJsonPath('data.1.delivery_state_label', 'Archived')
        ->assertJsonPath('data.1.latest_result', null);
});

test('control api project summaries include active component counts and status buckets', function () {
    ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'queue-worker',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinute(),
    ]);

    ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'scheduler',
        'current_status' => 'warning',
        'last_heartbeat_at' => now()->subMinutes(2),
    ]);

    ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'billing-sync',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(20),
        'is_stale' => true,
    ]);

    ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'first-heartbeat-pending',
        'current_status' => 'healthy',
        'last_heartbeat_at' => null,
    ]);

    ProjectComponent::factory()->archived()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'retired-worker',
        'current_status' => 'danger',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects')
        ->assertOk()
        ->assertJsonPath('data.0.checks_count', 5)
        ->assertJsonPath('data.0.enabled_checks_count', 4)
        ->assertJsonPath('data.0.disabled_checks_count', 1)
        ->assertJsonPath('data.0.components_count', 5)
        ->assertJsonPath('data.0.active_components_count', 4)
        ->assertJsonPath('data.0.archived_components_count', 1);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa')
        ->assertOk()
        ->assertJsonPath('data.checks_count', 5)
        ->assertJsonPath('data.enabled_checks_count', 4)
        ->assertJsonPath('data.disabled_checks_count', 1)
        ->assertJsonPath('data.components_count', 5)
        ->assertJsonPath('data.active_components_count', 4)
        ->assertJsonPath('data.archived_components_count', 1)
        ->assertJsonPath('data.status_counts.healthy', 1)
        ->assertJsonPath('data.status_counts.warning', 1)
        ->assertJsonPath('data.status_counts.danger', 1)
        ->assertJsonPath('data.status_counts.unknown', 1)
        ->assertJsonPath('data.status_counts.disabled', 1);
});

test('control api scopes projects to the api key owner', function () {
    $otherUser = User::factory()->create();
    $otherApiKey = ApiKey::factory()->create(['user_id' => $otherUser->id]);

    $this->withToken($otherApiKey->key)
        ->getJson('/api/v1/control/projects/scrappa')
        ->assertNotFound();

    $this->withToken($otherApiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/checks')
        ->assertNotFound();
});

test('control api queues all enabled project checks as a diagnostic batch', function () {
    Bus::fake();
    $this->travelTo('2026-05-09 12:00:00');

    $apiMonitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'url' => 'https://api.scrappa.test/health',
        'data_path' => 'data.status',
        'is_enabled' => true,
    ]);

    $disabledApiMonitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'disabled-health',
        'title' => 'Disabled health',
        'url' => 'https://api.scrappa.test/disabled',
        'is_enabled' => false,
    ]);

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'landing-page',
        'name' => 'Landing page',
        'url' => 'https://scrappa.test',
        'uptime_check' => true,
        'ssl_check' => true,
    ]);

    $pausedWebsite = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'paused-landing-page',
        'name' => 'Paused landing page',
        'url' => 'https://paused.scrappa.test',
        'uptime_check' => false,
        'ssl_check' => false,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/runs')
        ->assertAccepted()
        ->assertJsonPath('message', 'Project run queued.')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.checks_queued', 2)
        ->assertJsonPath('data.checks_skipped_already_queued', 0)
        ->assertJsonPath('data.run_batch.status', 'pending')
        ->assertJsonPath('data.run_batch.name', 'Control project run: scrappa')
        ->assertJsonPath('data.run_batch.total_jobs', 2)
        ->assertJsonPath('data.run_batch.pending_jobs', 2)
        ->assertJsonPath('data.run_batch.failed_jobs', 0);

    Bus::assertBatched(function ($batch): bool {
        return $batch->name === 'Control project run: scrappa'
            && $batch->jobs->count() === 2
            && $batch->jobs->contains(fn ($job): bool => $job instanceof RunApiMonitorDiagnosticJob
                && $job->monitor->package_name === 'search-health')
            && $batch->jobs->contains(fn ($job): bool => $job instanceof LogUptimeSslJob
                && $job->website->package_name === 'landing-page'
                && $job->onDemand === true)
            && ($batch->options['checkybot_control']['project_id'] ?? null) === $this->project->id
            && ($batch->options['checkybot_control']['user_id'] ?? null) === $this->user->id
            && ($batch->options['allowFailures'] ?? false) === true;
    });

    expect($apiMonitor->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-09 12:00:00')
        ->and($website->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-09 12:00:00')
        ->and($disabledApiMonitor->refresh()->diagnostic_queued_at)->toBeNull()
        ->and($pausedWebsite->refresh()->diagnostic_queued_at)->toBeNull();

    $this->assertDatabaseMissing('monitor_api_results', [
        'monitor_api_id' => MonitorApis::query()->where('package_name', 'search-health')->value('id'),
    ]);
});

test('control api skips already queued project diagnostics when creating a batch', function () {
    Bus::fake();
    $this->travelTo('2026-05-09 12:00:00');

    $apiMonitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'url' => 'https://api.scrappa.test/health',
        'is_enabled' => true,
    ]);

    $queuedApiMonitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'queued-search-health',
        'title' => 'Queued search health',
        'url' => 'https://api.scrappa.test/queued-health',
        'is_enabled' => true,
        'diagnostic_queued_at' => now()->subMinutes(2),
    ]);

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'landing-page',
        'name' => 'Landing page',
        'url' => 'https://scrappa.test',
        'uptime_check' => true,
    ]);

    $queuedWebsite = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'queued-landing-page',
        'name' => 'Queued landing page',
        'url' => 'https://queued.scrappa.test',
        'uptime_check' => true,
        'diagnostic_queued_at' => now()->subMinutes(2),
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/runs')
        ->assertAccepted()
        ->assertJsonPath('message', 'Project run queued.')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.checks_queued', 2)
        ->assertJsonPath('data.checks_skipped_already_queued', 2)
        ->assertJsonPath('data.run_batch.total_jobs', 2);

    Bus::assertBatched(function ($batch): bool {
        return $batch->name === 'Control project run: scrappa'
            && $batch->jobs->count() === 2
            && $batch->jobs->contains(fn ($job): bool => $job instanceof RunApiMonitorDiagnosticJob
                && $job->monitor->package_name === 'search-health')
            && $batch->jobs->contains(fn ($job): bool => $job instanceof LogUptimeSslJob
                && $job->website->package_name === 'landing-page')
            && ! $batch->jobs->contains(fn ($job): bool => $job instanceof RunApiMonitorDiagnosticJob
                && $job->monitor->package_name === 'queued-search-health')
            && ! $batch->jobs->contains(fn ($job): bool => $job instanceof LogUptimeSslJob
                && $job->website->package_name === 'queued-landing-page');
    });

    expect($apiMonitor->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-09 12:00:00')
        ->and($website->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-09 12:00:00')
        ->and($queuedApiMonitor->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-09 11:58:00')
        ->and($queuedWebsite->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-09 11:58:00');
});

test('control api reports already queued when every project diagnostic is active', function () {
    Bus::fake();
    $this->travelTo('2026-05-09 12:00:00');

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'queued-search-health',
        'title' => 'Queued search health',
        'url' => 'https://api.scrappa.test/queued-health',
        'is_enabled' => true,
        'diagnostic_queued_at' => now()->subMinutes(2),
    ]);

    Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'queued-landing-page',
        'name' => 'Queued landing page',
        'url' => 'https://queued.scrappa.test',
        'uptime_check' => true,
        'diagnostic_queued_at' => now()->subMinutes(2),
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/runs')
        ->assertOk()
        ->assertJsonPath('message', 'Project diagnostics are already queued.')
        ->assertJsonPath('data.status', 'already_queued')
        ->assertJsonPath('data.checks_queued', 0)
        ->assertJsonPath('data.checks_skipped_already_queued', 2)
        ->assertJsonPath('data.run_batch', null);

    Bus::assertNothingBatched();
});

test('control api returns queued project diagnostic batch status', function () {
    $createdAt = now()->subMinute()->timestamp;

    DB::table('job_batches')->insert([
        'id' => 'batch-control-scrappa',
        'name' => 'Control project run: scrappa',
        'total_jobs' => 3,
        'pending_jobs' => 1,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['failed-job-1']),
        'options' => serialize([
            'allowFailures' => true,
            'checkybot_control' => [
                'project_id' => $this->project->id,
                'user_id' => $this->user->id,
            ],
        ]),
        'cancelled_at' => null,
        'created_at' => $createdAt,
        'finished_at' => null,
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/runs/batch-control-scrappa')
        ->assertOk()
        ->assertJsonPath('data.project.key', 'scrappa')
        ->assertJsonPath('data.run_batch.id', 'batch-control-scrappa')
        ->assertJsonPath('data.run_batch.status', 'running')
        ->assertJsonPath('data.run_batch.name', 'Control project run: scrappa')
        ->assertJsonPath('data.run_batch.total_jobs', 3)
        ->assertJsonPath('data.run_batch.pending_jobs', 1)
        ->assertJsonPath('data.run_batch.failed_jobs', 1)
        ->assertJsonPath('data.run_batch.finished_at', null)
        ->assertJsonStructure(['data' => ['run_batch' => ['created_at']]]);
});

test('control api batch status lookup is scoped by stable metadata instead of mutable batch name', function () {
    DB::table('job_batches')->insert([
        'id' => 'batch-before-project-rename',
        'name' => 'Control project run: old-scrappa-key',
        'total_jobs' => 1,
        'pending_jobs' => 0,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => serialize([
            'checkybot_control' => [
                'project_id' => $this->project->id,
                'user_id' => $this->user->id,
            ],
        ]),
        'cancelled_at' => null,
        'created_at' => now()->subMinute()->timestamp,
        'finished_at' => now()->timestamp,
    ]);

    $this->project->update(['package_key' => 'renamed-scrappa']);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/renamed-scrappa/runs/batch-before-project-rename')
        ->assertOk()
        ->assertJsonPath('data.project.key', 'renamed-scrappa')
        ->assertJsonPath('data.run_batch.id', 'batch-before-project-rename')
        ->assertJsonPath('data.run_batch.name', 'Control project run: old-scrappa-key')
        ->assertJsonPath('data.run_batch.status', 'finished');
});

test('control api scopes diagnostic batch status to the project owner and control metadata', function () {
    $otherUser = User::factory()->create();
    $otherApiKey = ApiKey::factory()->create(['user_id' => $otherUser->id]);
    Project::factory()->create([
        'created_by' => $otherUser->id,
        'package_key' => 'scrappa',
        'name' => 'Other Scrappa',
    ]);

    DB::table('job_batches')->insert([
        'id' => 'batch-owned-by-primary-user',
        'name' => 'Control project run: scrappa',
        'total_jobs' => 1,
        'pending_jobs' => 1,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => serialize([
            'checkybot_control' => [
                'project_id' => $this->project->id,
                'user_id' => $this->user->id,
            ],
        ]),
        'cancelled_at' => null,
        'created_at' => now()->timestamp,
        'finished_at' => null,
    ]);

    $this->withToken($otherApiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/runs/batch-owned-by-primary-user')
        ->assertNotFound()
        ->assertJsonPath('message', 'Project run batch not found.');

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/runs/missing-batch')
        ->assertNotFound()
        ->assertJsonPath('message', 'Project run batch not found.');
});

test('control api project run reports when there are no enabled checks to queue', function () {
    Bus::fake();

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'disabled-health',
        'title' => 'Disabled health',
        'url' => 'https://api.scrappa.test/disabled',
        'is_enabled' => false,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/runs')
        ->assertOk()
        ->assertJsonPath('message', 'Project has no enabled checks to run.')
        ->assertJsonPath('data.status', 'no_enabled_checks')
        ->assertJsonPath('data.checks_queued', 0)
        ->assertJsonPath('data.checks_skipped_already_queued', 0)
        ->assertJsonPath('data.run_batch', null);

    Bus::assertNothingBatched();
});

test('queued project diagnostic jobs do not send notifications for check status transitions', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 200),
    ]);

    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'url' => 'https://api.scrappa.test/health',
        'data_path' => 'data.status',
        'current_status' => 'danger',
        'is_enabled' => true,
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $this->user->id,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    app()->call([new RunApiMonitorDiagnosticJob($monitor), 'handle']);

    $monitor->refresh();
    $result = $monitor->results()->latest('id')->first();

    expect($monitor->current_status)->toBe('danger')
        ->and($result?->status)->toBe('warning')
        ->and($result?->run_source)->toBe(RunSource::OnDemand)
        ->and($result?->is_on_demand)->toBeTrue();

    Mail::assertNothingSent();
});

test('control api trigger runs respect stored method and expected status', function () {
    Http::fake(function (HttpRequest $request) {
        expect($request->method())->toBe('POST');

        return Http::response([
            'data' => ['status' => 'created'],
        ], 201);
    });

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'create-job',
        'title' => 'Create job',
        'url' => 'https://api.scrappa.test/jobs',
        'http_method' => 'POST',
        'expected_status' => 201,
        'data_path' => 'data.status',
        'is_enabled' => true,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/create-job/runs')
        ->assertOk()
        ->assertJsonPath('data.check.key', 'create-job')
        ->assertJsonPath('data.result.success', true)
        ->assertJsonPath('data.result.http_code', 201)
        ->assertJsonPath('data.result.status', 'healthy');

    Http::assertSentCount(1);
});

test('control api rejects disabled check runs and relative urls without a project base url', function () {
    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'disabled-health',
        'is_enabled' => false,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/disabled-health/runs')
        ->assertStatus(409)
        ->assertJsonPath('message', 'Check is disabled. Enable or upsert the check before triggering a run.');

    $projectWithoutBaseUrl = Project::factory()->create([
        'created_by' => $this->user->id,
        'package_key' => 'missing-base-url',
        'base_url' => null,
    ]);

    $this->withToken($this->apiKey->key)
        ->putJson("/api/v1/control/projects/{$projectWithoutBaseUrl->package_key}/checks/relative-health", [
            'name' => 'Relative health',
            'url' => '/health',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

test('control api rejects invalid schedules', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/invalid-schedule', [
            'name' => 'Invalid schedule',
            'url' => '/health',
            'schedule' => 'every_friday',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('schedule');
});

test('control api requires body type when request body is provided', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/login-api', [
            'name' => 'Login API',
            'url' => '/login',
            'method' => 'POST',
            'request_body' => ['probe' => true],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_body_type');

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/empty-login-api', [
            'name' => 'Empty Login API',
            'url' => '/login',
            'method' => 'POST',
            'request_body' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_body_type');
});

test('control api limits request body size', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/login-api', [
            'name' => 'Login API',
            'url' => '/login',
            'method' => 'POST',
            'request_body_type' => 'raw',
            'request_body' => str_repeat('a', 65536),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_body');
});

test('control api rejects unstructured json and form request bodies', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/login-api', [
            'name' => 'Login API',
            'url' => '/login',
            'method' => 'POST',
            'request_body_type' => 'json',
            'request_body' => 'email=monitor@example.com',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_body');

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/token-api', [
            'name' => 'Token API',
            'url' => '/token',
            'method' => 'POST',
            'request_body_type' => 'form',
            'request_body' => 'grant_type=client_credentials',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_body');
});

test('control api rejects non string raw request bodies', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/raw-login-api', [
            'name' => 'Raw Login API',
            'url' => '/login',
            'method' => 'POST',
            'request_body_type' => 'raw',
            'request_body' => ['probe' => true],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_body');
});

test('mcp endpoint lists tools and calls the shared control surface', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'me')
        ->assertJsonFragment(['description' => 'Optional check type. Required when multiple check surfaces share the same key.'])
        ->assertJsonFragment(['enum' => ['api', 'component', 'website']])
        ->assertJsonFragment(['name' => 'get_run_batch']);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'ping',
        ])
        ->assertOk()
        ->assertJsonPath('result.ok', true);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'search-health',
                    'name' => 'Search health',
                    'url' => '/health',
                    'headers' => [
                        'Authorization' => 'Bearer mcp-secret',
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.check.key', 'search-health')
        ->assertJsonPath('result.structuredContent.check.schedule', '5m')
        ->assertJsonPath('result.structuredContent.check.headers.Authorization', '[redacted]');

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'search-health',
        'url' => 'https://api.scrappa.test/health',
        'package_schedule' => '5m',
        'package_interval' => '5m',
    ]);
});

test('mcp upsert check creates package managed website checks', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 20,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'marketing-site',
                    'type' => 'website',
                    'check_types' => ['uptime', 'ssl'],
                    'name' => 'Marketing site',
                    'url' => '/status',
                    'schedule' => '10m',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.created', true)
        ->assertJsonPath('result.structuredContent.check.key', 'marketing-site')
        ->assertJsonPath('result.structuredContent.check.type', 'website')
        ->assertJsonPath('result.structuredContent.check.check_types', ['uptime', 'ssl'])
        ->assertJsonPath('result.structuredContent.check.url', 'https://api.scrappa.test/status')
        ->assertJsonPath('result.structuredContent.check.schedule', '10m');

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'marketing-site',
        'source' => 'package',
        'uptime_check' => true,
        'uptime_interval' => 10,
        'ssl_check' => true,
        'package_interval' => '10m',
    ]);
});

test('mcp disable check accepts type to resolve ambiguous check keys', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'is_enabled' => true,
    ]);

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'uptime_check' => true,
        'ssl_check' => true,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 44,
            'method' => 'tools/call',
            'params' => [
                'name' => 'disable_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'check' => 'shared-health',
                    'type' => 'website',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.type', 'website')
        ->assertJsonPath('result.structuredContent.enabled', false);

    expect($monitor->refresh()->is_enabled)->toBeTrue()
        ->and($website->refresh()->uptime_check)->toBeFalse()
        ->and($website->ssl_check)->toBeFalse();
});

test('mcp latest failures tool includes website and component failures', function () {
    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'App website',
        'source' => 'package',
        'package_name' => 'app-uptime',
        'current_status' => 'danger',
        'uptime_check' => true,
    ]);
    WebsiteLogHistory::factory()->transportError('tls')->create([
        'website_id' => $website->id,
        'summary' => 'Website TLS handshake failed.',
        'created_at' => now()->subMinutes(2),
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'billing-worker',
        'current_status' => 'danger',
        'is_archived' => false,
    ]);
    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $component->id,
        'component_name' => 'billing-worker',
        'status' => 'danger',
        'summary' => 'Billing worker stopped reporting.',
        'observed_at' => now()->subMinute(),
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'latest_failures',
                'arguments' => [
                    'project' => 'scrappa',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.0.check.type', 'component')
        ->assertJsonPath('result.structuredContent.0.check.key', 'billing-worker')
        ->assertJsonPath('result.structuredContent.1.check.type', 'website')
        ->assertJsonPath('result.structuredContent.1.check.key', 'app-uptime');
});

test('mcp recent runs tool includes website diagnostics and component heartbeats', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
    ]);
    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $monitor->id,
        'summary' => 'API diagnostic completed.',
        'created_at' => now()->subMinutes(2),
    ]);

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'app-uptime',
        'name' => 'App website',
        'uptime_check' => true,
    ]);
    WebsiteLogHistory::factory()->onDemand()->create([
        'website_id' => $website->id,
        'summary' => 'Website diagnostic completed.',
        'created_at' => now()->subMinute(),
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'billing-worker',
        'current_status' => 'healthy',
    ]);
    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $component->id,
        'component_name' => 'billing-worker',
        'status' => 'healthy',
        'event' => 'heartbeat',
        'summary' => 'Billing worker heartbeat completed.',
        'observed_at' => now(),
        'created_at' => now()->subMinutes(3),
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'recent_runs',
                'arguments' => [
                    'project' => 'scrappa',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.0.check.type', 'component')
        ->assertJsonPath('result.structuredContent.0.check.key', 'billing-worker')
        ->assertJsonPath('result.structuredContent.0.run_source', 'heartbeat')
        ->assertJsonPath('result.structuredContent.0.summary', 'Billing worker heartbeat completed.')
        ->assertJsonPath('result.structuredContent.1.check.type', 'website')
        ->assertJsonPath('result.structuredContent.1.check.key', 'app-uptime')
        ->assertJsonPath('result.structuredContent.1.summary', 'Website diagnostic completed.')
        ->assertJsonPath('result.structuredContent.2.check.type', 'api')
        ->assertJsonPath('result.structuredContent.2.check.key', 'api-health');
});

test('mcp endpoint rejects invalid schedules with a field validation error', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'search-health',
                    'name' => 'Search health',
                    'url' => '/health',
                    'schedule' => 'every_friday',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.data.errors.schedule.0', 'The schedule format is invalid. Use format: {number}{s|m|h|d} or every_{number}_{seconds|minutes|hours|days}.');
});

test('mcp endpoint rejects malformed and unsupported check urls before upserting definitions', function (string $url) {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 30,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'bad-url',
                    'name' => 'Bad URL',
                    'url' => $url,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonValidationErrorFor('url', 'error.data.errors');

    $this->assertDatabaseMissing('monitor_apis', [
        'package_name' => 'bad-url',
    ]);
})->with([
    'unsupported scheme' => ['ftp://api.scrappa.test/health'],
    'protocol relative url' => ['//api.scrappa.test/health'],
    'missing scheme separator' => ['https//api.scrappa.test/health'],
]);

test('mcp endpoint rejects invalid regex assertion patterns', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'regex-health',
                    'name' => 'Regex health',
                    'url' => '/health',
                    'assertions' => [
                        [
                            'type' => 'regex_match',
                            'path' => '$.status',
                            'regex_pattern' => '/[unterminated/',
                        ],
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('error.code', -32602);

    expect($response->json('error.data.errors')['assertions.0.regex_pattern'][0])
        ->toBe('The regex pattern must be a valid PHP regular expression.');

    $this->assertDatabaseMissing('monitor_apis', [
        'package_name' => 'regex-health',
    ]);
});

test('mcp endpoint requires body type when request body is provided', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'login-api',
                    'name' => 'Login API',
                    'url' => '/login',
                    'method' => 'POST',
                    'request_body' => ['probe' => true],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.data.errors.request_body_type.0', 'The request body type field is required when request body is present.');

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'empty-login-api',
                    'name' => 'Empty Login API',
                    'url' => '/login',
                    'method' => 'POST',
                    'request_body' => [],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.data.errors.request_body_type.0', 'The request body type field is required when request body is present.');
});

test('mcp endpoint rejects unstructured json request body', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'login-api',
                    'name' => 'Login API',
                    'url' => '/login',
                    'method' => 'POST',
                    'request_body_type' => 'json',
                    'request_body' => 'email=monitor@example.com',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.data.errors.request_body.0', 'The request_body field must be a JSON object or array for json request bodies.');
});

test('mcp endpoint rejects non string raw request body', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'raw-login-api',
                    'name' => 'Raw Login API',
                    'url' => '/login',
                    'method' => 'POST',
                    'request_body_type' => 'raw',
                    'request_body' => ['probe' => true],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.data.errors.request_body.0', 'The request_body field must be a string for raw request bodies.');
});
