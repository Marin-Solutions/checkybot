<?php

use App\Enums\RunSource;
use App\Models\ApiKey;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);
    $this->project = Project::factory()->create([
        'created_by' => $this->user->id,
        'package_key' => 'checkybot-app',
        'name' => 'Checkybot App',
        'environment' => 'production',
        'base_url' => 'https://checkybot.test',
        'repository' => 'marin/checkybot',
    ]);
});

test('project read endpoints require a valid bearer token', function () {
    $this->getJson("/api/v1/projects/{$this->project->id}")
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Bearer API key is missing');

    $this->withToken('ck_invalid')
        ->getJson("/api/v1/projects/{$this->project->id}/checks")
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid or expired API key');
});

test('project read endpoints are scoped to the api key owner', function () {
    $otherUser = User::factory()->create();
    $otherApiKey = ApiKey::factory()->create(['user_id' => $otherUser->id]);

    $this->withToken($otherApiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}")
        ->assertNotFound();

    $this->withToken($otherApiKey->key)
        ->getJson("/api/v1/projects/{$this->project->package_key}/checks")
        ->assertNotFound();
});

test('project read endpoints reject ambiguous package keys across environments', function () {
    Project::factory()->create([
        'created_by' => $this->user->id,
        'package_key' => $this->project->package_key,
        'environment' => 'staging',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->package_key}")
        ->assertNotFound();

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}")
        ->assertOk()
        ->assertJsonPath('data.environment', 'production');
});

test('project read endpoints support unambiguous numeric package keys', function () {
    $packageKeyMatch = Project::factory()->create([
        'created_by' => $this->user->id,
        'package_key' => '987654321',
        'name' => 'Numeric package key match',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$packageKeyMatch->package_key}")
        ->assertOk()
        ->assertJsonPath('data.id', $packageKeyMatch->id)
        ->assertJsonPath('data.key', $packageKeyMatch->package_key);
});

test('project read endpoints reject numeric keys that match both id and package key', function () {
    $idMatch = Project::factory()->create([
        'id' => 123,
        'created_by' => $this->user->id,
        'package_key' => 'id-match',
        'name' => 'ID match',
    ]);

    $packageKeyMatch = Project::factory()->create([
        'created_by' => $this->user->id,
        'package_key' => (string) $idMatch->id,
        'name' => 'Numeric package key match',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$idMatch->id}")
        ->assertNotFound();
});

test('project read endpoints treat zero padded numeric package keys as package keys', function () {
    Project::factory()->create([
        'id' => 124,
        'created_by' => $this->user->id,
        'package_key' => 'id-match',
        'name' => 'ID match',
    ]);

    $packageKeyMatch = Project::factory()->create([
        'created_by' => $this->user->id,
        'package_key' => '00124',
        'name' => 'Zero padded package key match',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$packageKeyMatch->package_key}")
        ->assertOk()
        ->assertJsonPath('data.id', $packageKeyMatch->id)
        ->assertJsonPath('data.key', '00124');
});

test('project read endpoint returns project metadata without secrets', function () {
    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
    ]);

    Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'homepage',
        'uptime_check' => true,
        'ssl_check' => false,
    ]);

    Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'status-page',
        'uptime_check' => true,
        'ssl_check' => true,
    ]);

    Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'disabled-site',
        'uptime_check' => false,
        'ssl_check' => false,
    ]);

    ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'queue',
    ]);

    ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'manual-cache',
        'source' => 'manual',
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->project->id)
        ->assertJsonPath('data.key', 'checkybot-app')
        ->assertJsonPath('data.name', 'Checkybot App')
        ->assertJsonPath('data.environment', 'production')
        ->assertJsonPath('data.checks_count', 5)
        ->assertJsonPath('data.api_checks_count', 1)
        ->assertJsonPath('data.website_checks_count', 3)
        ->assertJsonPath('data.component_checks_count', 1);

    expect(json_encode($response->json()))->not->toContain($this->project->token);
});

test('project read endpoint exposes setup verification diagnostics', function () {
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

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$waitingForRegistration->id}")
        ->assertOk()
        ->assertJsonPath('data.setup_verification.state', 'waiting_for_registration')
        ->assertJsonPath('data.setup_verification.label', 'Waiting for registration')
        ->assertJsonPath('data.setup_verification.tone', 'warning')
        ->assertJsonPath('data.setup_verification.steps.0.title', 'Laravel package registration')
        ->assertJsonPath('data.setup_verification.steps.0.status', 'pending')
        ->assertJsonPath('data.setup_verification.steps.1.status', 'pending')
        ->assertJsonPath('data.setup_verification.action', 'Copy the guided install snippet into the Laravel app, then run `php artisan checkybot:sync` once to trigger registration.');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$waitingForFirstSync->id}")
        ->assertOk()
        ->assertJsonPath('data.setup_verification.state', 'waiting_for_first_sync')
        ->assertJsonPath('data.setup_verification.steps.0.status', 'complete')
        ->assertJsonPath('data.setup_verification.steps.0.description', 'Registration received from https://waiting-sync.test/checkybot/identity.')
        ->assertJsonPath('data.setup_verification.steps.1.title', 'First package sync')
        ->assertJsonPath('data.setup_verification.steps.1.status', 'pending')
        ->assertJsonPath('data.setup_verification.summary', 'Checkybot has seen the Laravel package register this application, but the first package sync has not arrived yet.');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$staleSync->id}")
        ->assertOk()
        ->assertJsonPath('data.setup_verification.state', 'sync_stale')
        ->assertJsonPath('data.setup_verification.label', 'Sync stale')
        ->assertJsonPath('data.setup_verification.tone', 'warning')
        ->assertJsonPath('data.setup_verification.steps.1.status', 'stale')
        ->assertJsonPath('data.setup_verification.steps.1.description', 'Last sync received 16 minutes ago, which is outside the 15 minute freshness window.');
});

test('checks read endpoint returns uptime ssl and api checks with current result context', function () {
    $uptime = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'homepage-uptime',
        'name' => 'Homepage uptime',
        'url' => 'https://checkybot.test',
        'uptime_check' => true,
        'ssl_check' => false,
        'uptime_interval' => 5,
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'status_summary' => 'Website responded successfully.',
    ]);

    WebsiteLogHistory::factory()->create([
        'website_id' => $uptime->id,
        'http_status_code' => 200,
        'speed' => 123,
        'status' => 'healthy',
        'summary' => 'Website responded successfully.',
    ]);

    $ssl = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'homepage-ssl',
        'name' => 'Homepage SSL',
        'url' => 'https://checkybot.test',
        'uptime_check' => false,
        'ssl_check' => true,
        'uptime_interval' => 1440,
        'package_interval' => '1d',
        'current_status' => 'warning',
        'status_summary' => 'Certificate expires soon.',
    ]);

    WebsiteLogHistory::factory()->create([
        'website_id' => $ssl->id,
        'http_status_code' => 200,
        'speed' => 98,
        'status' => 'warning',
        'summary' => 'Certificate expires soon.',
    ]);

    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'url' => 'https://checkybot.test/api/health?token=url-secret&api_key=query-secret&auth=auth-query-secret&plain=value',
        'http_method' => 'GET',
        'request_path' => '/api/health?api_key=path-secret&auth=path-auth-secret&plain=value',
        'headers' => [
            'Accept' => 'application/json',
            'Auth' => 'auth-header-secret',
            'Authorization' => 'Bearer secret-token',
            'Cookie' => 'session=cookie-secret',
            'Proxy-Authorization' => 'Basic proxy-secret',
            'X-Api-Key' => 'secret-api-key',
        ],
        'request_body_type' => 'raw',
        'request_body' => '   ',
        'package_interval' => '5m',
        'is_enabled' => true,
        'current_status' => 'danger',
        'status_summary' => 'API returned HTTP 500.',
    ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $api->id,
        'data_path' => 'status',
        'assertion_type' => 'exists',
    ]);

    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $api->id,
        'summary' => 'API returned HTTP 500.',
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->package_key}/checks")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.type', 'api')
        ->assertJsonPath('data.0.id', "api:{$api->id}")
        ->assertJsonPath('data.0.key', 'api-health')
        ->assertJsonPath('data.0.target', 'https://checkybot.test/api/health?token=%5Bredacted%5D&api_key=%5Bredacted%5D&auth=%5Bredacted%5D&plain=value')
        ->assertJsonPath('data.0.request_path', '/api/health?api_key=%5Bredacted%5D&auth=%5Bredacted%5D&plain=value')
        ->assertJsonPath('data.0.interval', '5m')
        ->assertJsonPath('data.0.enabled', true)
        ->assertJsonPath('data.0.status', 'danger')
        ->assertJsonPath('data.0.headers.Auth', '[redacted]')
        ->assertJsonPath('data.0.headers.Authorization', '[redacted]')
        ->assertJsonPath('data.0.headers.Cookie', '[redacted]')
        ->assertJsonPath('data.0.headers.Proxy-Authorization', '[redacted]')
        ->assertJsonPath('data.0.headers.X-Api-Key', '[redacted]')
        ->assertJsonPath('data.0.has_request_body', true)
        ->assertJsonPath('data.0.assertions.0.data_path', 'status')
        ->assertJsonPath('data.0.latest_result.status', 'danger')
        ->assertJsonPath('data.1.id', "ssl:{$ssl->id}")
        ->assertJsonPath('data.1.type', 'ssl')
        ->assertJsonPath('data.1.status', 'warning')
        ->assertJsonPath('data.2.id', "uptime:{$uptime->id}")
        ->assertJsonPath('data.2.type', 'uptime')
        ->assertJsonPath('data.2.latest_result.http_code', 200);

    expect(json_encode($response->json()))->not->toContain('secret-token')
        ->and(json_encode($response->json()))->not->toContain('cookie-secret')
        ->and(json_encode($response->json()))->not->toContain('proxy-secret')
        ->and(json_encode($response->json()))->not->toContain('secret-api-key')
        ->and(json_encode($response->json()))->not->toContain('url-secret')
        ->and(json_encode($response->json()))->not->toContain('query-secret')
        ->and(json_encode($response->json()))->not->toContain('auth-query-secret')
        ->and(json_encode($response->json()))->not->toContain('path-secret')
        ->and(json_encode($response->json()))->not->toContain('path-auth-secret')
        ->and(json_encode($response->json()))->not->toContain('auth-header-secret');
});

test('project check read endpoints include component declarations without heartbeat history', function () {
    $component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'queue',
        'declared_interval' => '5m',
        'interval_minutes' => 5,
        'summary' => 'Queue depth is elevated.',
    ]);

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'project_component_id' => $component->id,
        'source' => 'package',
        'package_name' => 'queue-api',
        'is_enabled' => true,
        'current_status' => 'warning',
    ]);

    ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'manual-cache',
        'source' => 'manual',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->package_key}/checks")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.id', "component:{$component->id}")
        ->assertJsonPath('data.1.database_id', $component->id)
        ->assertJsonPath('data.1.key', 'queue')
        ->assertJsonPath('data.1.type', 'component')
        ->assertJsonPath('data.1.storage', 'project_component')
        ->assertJsonPath('data.1.interval', '5m')
        ->assertJsonPath('data.1.interval_minutes', 5)
        ->assertJsonPath('data.1.enabled', true)
        ->assertJsonPath('data.1.status', 'warning')
        ->assertJsonPath('data.1.status_summary', 'Queue depth is elevated.')
        ->assertJsonPath('data.1.latest_result', null)
        ->assertJsonMissingPath('data.1.reported_status')
        ->assertJsonMissingPath('data.1.metrics');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/component:{$component->id}")
        ->assertOk()
        ->assertJsonPath('data.key', 'queue')
        ->assertJsonPath('data.latest_result', null);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/queue/results?limit=1")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/queue/results?run_source=scheduled")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/manual-cache")
        ->assertNotFound();
});

test('single check and recent result endpoints return investigation context', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'current_status' => 'danger',
    ]);

    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $api->id,
        'created_at' => now()->subMinutes(5),
    ]);
    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $api->id,
        'status' => 'danger',
        'summary' => 'API returned HTTP 500.',
        'created_at' => now(),
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api:{$api->id}")
        ->assertOk()
        ->assertJsonPath('data.id', "api:{$api->id}")
        ->assertJsonPath('data.key', 'api-health')
        ->assertJsonPath('data.latest_result.status', 'danger');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health/results?limit=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.check_id', "api:{$api->id}")
        ->assertJsonPath('data.0.status', 'danger')
        ->assertJsonPath('data.0.summary', 'API returned HTTP 500.');
});

test('single check and recent result endpoints can disambiguate shared package keys by type', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'title' => 'API shared health',
        'current_status' => 'danger',
    ]);

    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'name' => 'Homepage shared health',
        'uptime_check' => true,
        'ssl_check' => true,
        'current_status' => 'warning',
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'shared-health',
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
    ]);

    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $api->id,
        'summary' => 'API shared health failed.',
        'created_at' => now()->subMinutes(3),
    ]);

    $websiteResult = WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'summary' => 'Website uptime is warning.',
        'status' => 'warning',
        'created_at' => now()->subMinutes(2),
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/shared-health")
        ->assertNotFound();

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/shared-health?type=api")
        ->assertOk()
        ->assertJsonPath('data.id', "api:{$api->id}")
        ->assertJsonPath('data.type', 'api')
        ->assertJsonPath('data.latest_result.summary', 'API shared health failed.');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/shared-health?type=uptime")
        ->assertOk()
        ->assertJsonPath('data.id', "uptime:{$website->id}")
        ->assertJsonPath('data.type', 'uptime');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/shared-health?type=ssl")
        ->assertOk()
        ->assertJsonPath('data.id', "ssl:{$website->id}")
        ->assertJsonPath('data.type', 'ssl');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/shared-health?type=component")
        ->assertOk()
        ->assertJsonPath('data.id', "component:{$component->id}")
        ->assertJsonPath('data.type', 'component')
        ->assertJsonPath('data.latest_result', null);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/shared-health/results?type=uptime&limit=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $websiteResult->id)
        ->assertJsonPath('data.0.check_id', "uptime:{$website->id}")
        ->assertJsonPath('data.0.summary', 'Website uptime is warning.');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/shared-health/results?type=component&limit=1")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('recent api check results can be filtered by run source', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
    ]);

    $scheduled = MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $api->id,
        'summary' => 'Scheduled run is healthy.',
        'run_source' => RunSource::Scheduled,
        'is_on_demand' => false,
        'created_at' => now()->subMinutes(5),
    ]);

    $onDemand = MonitorApiResult::factory()->onDemand()->failed()->create([
        'monitor_api_id' => $api->id,
        'summary' => 'Diagnostic run failed.',
        'created_at' => now(),
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health/results?run_source=scheduled")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $scheduled->id)
        ->assertJsonPath('data.0.run_source', RunSource::Scheduled->value);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health/results?run_source=on_demand")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $onDemand->id)
        ->assertJsonPath('data.0.run_source', RunSource::OnDemand->value);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health/results?run_source=all")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $onDemand->id)
        ->assertJsonPath('data.1.id', $scheduled->id);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health/results?run_source=heartbeat")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('run_source');
});

test('recent website check results can be filtered by run source', function () {
    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'homepage',
        'uptime_check' => true,
        'ssl_check' => false,
    ]);

    $scheduled = WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'summary' => 'Scheduled uptime is healthy.',
        'run_source' => RunSource::Scheduled,
        'is_on_demand' => false,
        'created_at' => now()->subMinutes(5),
    ]);

    $onDemand = WebsiteLogHistory::factory()->onDemand()->transportError()->create([
        'website_id' => $website->id,
        'summary' => 'Diagnostic uptime failed.',
        'created_at' => now(),
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/homepage/results?run_source=scheduled")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $scheduled->id)
        ->assertJsonPath('data.0.run_source', RunSource::Scheduled->value);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/homepage/results?run_source=on_demand")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $onDemand->id)
        ->assertJsonPath('data.0.run_source', RunSource::OnDemand->value);
});

test('recent check results default to all run sources and reject invalid filters', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
    ]);

    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $api->id,
        'run_source' => RunSource::Scheduled,
        'is_on_demand' => false,
        'created_at' => now()->subMinutes(5),
    ]);
    MonitorApiResult::factory()->onDemand()->failed()->create([
        'monitor_api_id' => $api->id,
        'created_at' => now(),
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health/results")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health/results?run_source=manual")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('run_source');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health?type=website")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health/results?type=website")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

test('project check read endpoints redact saved api response body evidence', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'current_status' => 'danger',
    ]);

    $longValue = str_repeat('x', 4200);

    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $api->id,
        'status' => 'danger',
        'summary' => 'API returned HTTP 500.',
        'response_body' => [
            'error' => 'upstream unavailable',
            'trace_id' => 'trace-123',
            'details' => $longValue,
            MonitorApiResult::RAW_BODY_KEY => 'Token expired. Your token was: raw-body-secret',
            MonitorApiResult::ERROR_METADATA_KEY => 'cURL error included error-metadata-secret',
            MonitorApis::LEGACY_RAW_BODY_KEY => 'Token expired. Your token was: legacy-raw-secret',
            'access_token' => 'body-token-secret',
            'nested' => [
                'password' => 'body-password-secret',
                'detail' => 'resolver timeout',
            ],
        ],
    ]);

    $showResponse = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api:{$api->id}")
        ->assertOk()
        ->assertJsonPath('data.latest_result.response_body.error', 'upstream unavailable')
        ->assertJsonPath('data.latest_result.response_body.trace_id', 'trace-123')
        ->assertJsonPath('data.latest_result.response_body.'.MonitorApiResult::RAW_BODY_KEY, '[redacted]')
        ->assertJsonPath('data.latest_result.response_body.'.MonitorApiResult::ERROR_METADATA_KEY, '[redacted]')
        ->assertJsonPath('data.latest_result.response_body.'.MonitorApis::LEGACY_RAW_BODY_KEY, '[redacted]')
        ->assertJsonPath('data.latest_result.response_body.access_token', '[redacted]')
        ->assertJsonPath('data.latest_result.response_body.nested.password', '[redacted]')
        ->assertJsonPath('data.latest_result.response_body.nested.detail', 'resolver timeout');

    expect($showResponse->json('data.latest_result.response_body.details'))->toEndWith('... [truncated]')
        ->and($showResponse->json('data.latest_result.response_body.details'))->not->toBe($longValue);

    $resultsResponse = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health/results")
        ->assertOk()
        ->assertJsonPath('data.0.response_body.error', 'upstream unavailable')
        ->assertJsonPath('data.0.response_body.'.MonitorApiResult::RAW_BODY_KEY, '[redacted]')
        ->assertJsonPath('data.0.response_body.access_token', '[redacted]')
        ->assertJsonPath('data.0.response_body.nested.password', '[redacted]');

    expect(json_encode($showResponse->json()))->not->toContain('raw-body-secret')
        ->and(json_encode($showResponse->json()))->not->toContain('error-metadata-secret')
        ->and(json_encode($showResponse->json()))->not->toContain('legacy-raw-secret')
        ->and(json_encode($showResponse->json()))->not->toContain('body-token-secret')
        ->and(json_encode($showResponse->json()))->not->toContain('body-password-secret')
        ->and(json_encode($resultsResponse->json()))->not->toContain('raw-body-secret')
        ->and(json_encode($resultsResponse->json()))->not->toContain('body-token-secret')
        ->and(json_encode($resultsResponse->json()))->not->toContain('body-password-secret');
});

test('project check read endpoints expose redacted api transport and header evidence', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'current_status' => 'danger',
    ]);

    $longValue = str_repeat('x', 4200);

    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $api->id,
        'http_code' => 0,
        'status' => 'danger',
        'summary' => 'API check failed because DNS lookup failed.',
        'transport_error_type' => 'dns',
        'transport_error_message' => 'Could not resolve https://user:transport-secret@api.scrappa.test/private/request-secret?debug=transport-query-secret, with Bearer transport-bearer-secret',
        'transport_error_code' => 6,
        'request_headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer request-secret',
            'Proxy-Authorization' => 'Basic proxy-secret',
            'X-Api-Key' => 'package-secret',
            'x-debug-trace' => $longValue,
        ],
        'response_headers' => [
            'content-type' => 'application/json',
            'set-cookie' => 'session=response-secret',
            'x-request-id' => 'req-123',
        ],
    ]);

    $showResponse = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api:{$api->id}")
        ->assertOk()
        ->assertJsonPath('data.latest_result.transport_error_type', 'dns')
        ->assertJsonPath('data.latest_result.transport_error_message', 'Could not resolve https://api.scrappa.test/[redacted-url], with Bearer [redacted]')
        ->assertJsonPath('data.latest_result.transport_error_code', 6)
        ->assertJsonPath('data.latest_result.request_headers.Accept', 'application/json')
        ->assertJsonPath('data.latest_result.request_headers.Authorization', '[redacted]')
        ->assertJsonPath('data.latest_result.request_headers.Proxy-Authorization', '[redacted]')
        ->assertJsonPath('data.latest_result.request_headers.X-Api-Key', '[redacted]')
        ->assertJsonPath('data.latest_result.response_headers.content-type', 'application/json')
        ->assertJsonPath('data.latest_result.response_headers.set-cookie', '[redacted]')
        ->assertJsonPath('data.latest_result.response_headers.x-request-id', 'req-123');

    $resultsResponse = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health/results")
        ->assertOk()
        ->assertJsonPath('data.0.transport_error_type', 'dns')
        ->assertJsonPath('data.0.transport_error_message', 'Could not resolve https://api.scrappa.test/[redacted-url], with Bearer [redacted]')
        ->assertJsonPath('data.0.transport_error_code', 6)
        ->assertJsonPath('data.0.request_headers.Accept', 'application/json')
        ->assertJsonPath('data.0.request_headers.Authorization', '[redacted]')
        ->assertJsonPath('data.0.request_headers.Proxy-Authorization', '[redacted]')
        ->assertJsonPath('data.0.request_headers.X-Api-Key', '[redacted]')
        ->assertJsonPath('data.0.response_headers.content-type', 'application/json')
        ->assertJsonPath('data.0.response_headers.set-cookie', '[redacted]')
        ->assertJsonPath('data.0.response_headers.x-request-id', 'req-123');

    expect($showResponse->json('data.latest_result.request_headers.x-debug-trace'))->toEndWith('... [truncated]')
        ->and($showResponse->json('data.latest_result.request_headers.x-debug-trace'))->not->toBe($longValue)
        ->and($resultsResponse->json('data.0.request_headers.x-debug-trace'))->toEndWith('... [truncated]')
        ->and($resultsResponse->json('data.0.request_headers.x-debug-trace'))->not->toBe($longValue)
        ->and(json_encode($showResponse->json()))->not->toContain('request-secret')
        ->and(json_encode($showResponse->json()))->not->toContain('proxy-secret')
        ->and(json_encode($showResponse->json()))->not->toContain('package-secret')
        ->and(json_encode($showResponse->json()))->not->toContain('response-secret')
        ->and(json_encode($showResponse->json()))->not->toContain('transport-secret')
        ->and(json_encode($showResponse->json()))->not->toContain('transport-query-secret')
        ->and(json_encode($showResponse->json()))->not->toContain('transport-bearer-secret')
        ->and(json_encode($resultsResponse->json()))->not->toContain('request-secret')
        ->and(json_encode($resultsResponse->json()))->not->toContain('proxy-secret')
        ->and(json_encode($resultsResponse->json()))->not->toContain('package-secret')
        ->and(json_encode($resultsResponse->json()))->not->toContain('response-secret')
        ->and(json_encode($resultsResponse->json()))->not->toContain('transport-secret')
        ->and(json_encode($resultsResponse->json()))->not->toContain('transport-query-secret')
        ->and(json_encode($resultsResponse->json()))->not->toContain('transport-bearer-secret');
});

test('project check read endpoints redact top level raw response body strings', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'current_status' => 'danger',
    ]);

    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $api->id,
        'status' => 'danger',
        'summary' => 'API returned a raw upstream body.',
        'response_body' => 'Token expired. Your token was: top-level-body-secret',
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api-health/results")
        ->assertOk()
        ->assertJsonPath('data.0.response_body', '[redacted]');

    expect(json_encode($response->json()))->not->toContain('top-level-body-secret');
});

test('single check lookup does not match ambiguous bare database ids', function () {
    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'homepage-uptime',
        'uptime_check' => true,
        'ssl_check' => false,
    ]);

    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/{$website->id}")
        ->assertNotFound();

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api:{$api->id}")
        ->assertOk()
        ->assertJsonPath('data.key', 'api-health');
});

test('single check lookup rejects ambiguous package keys across check types', function () {
    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'uptime_check' => true,
        'ssl_check' => false,
    ]);

    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'shared-health',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/shared-health")
        ->assertNotFound();

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/uptime:{$website->id}")
        ->assertOk()
        ->assertJsonPath('data.id', "uptime:{$website->id}");

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api:{$api->id}")
        ->assertOk()
        ->assertJsonPath('data.id', "api:{$api->id}");
});

test('single check lookup prefers stable typed ids over colliding package keys', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
    ]);

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => "api:{$api->id}",
        'title' => 'Colliding package key',
    ]);

    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $api->id,
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api:{$api->id}")
        ->assertOk()
        ->assertJsonPath('data.id', "api:{$api->id}")
        ->assertJsonPath('data.key', 'api-health');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api:{$api->id}/results")
        ->assertOk()
        ->assertJsonPath('data.0.check_id', "api:{$api->id}");
});

test('single check endpoint redacts sensitive request path query values', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'request_path' => '/api/health?api_key=path-secret&plain=value',
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api:{$api->id}")
        ->assertOk()
        ->assertJsonPath('data.request_path', '/api/health?api_key=%5Bredacted%5D&plain=value');

    expect(json_encode($response->json()))->not->toContain('path-secret');
});

test('single check endpoint redacts url userinfo and nested query credentials', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'url' => 'https://url-user:url-pass@checkybot.test/api/health?auth[token]=nested-secret&plain=value',
        'request_path' => 'https://path-user:path-pass@checkybot.test/api/health?auth[token]=path-secret&plain=value',
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api:{$api->id}")
        ->assertOk()
        ->assertJsonPath('data.target', 'https://[redacted]@checkybot.test/api/health?auth=%5Bredacted%5D&plain=value')
        ->assertJsonPath('data.url', 'https://[redacted]@checkybot.test/api/health?auth=%5Bredacted%5D&plain=value')
        ->assertJsonPath('data.request_path', 'https://[redacted]@checkybot.test/api/health?auth=%5Bredacted%5D&plain=value');

    expect(json_encode($response->json()))->not->toContain('url-user')
        ->and(json_encode($response->json()))->not->toContain('url-pass')
        ->and(json_encode($response->json()))->not->toContain('path-user')
        ->and(json_encode($response->json()))->not->toContain('path-pass')
        ->and(json_encode($response->json()))->not->toContain('nested-secret')
        ->and(json_encode($response->json()))->not->toContain('path-secret');
});

test('single check endpoint redacts scheme relative url userinfo', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'url' => '//url-user:url-pass@checkybot.test/api/health?plain=value',
        'request_path' => '//path-user:path-pass@checkybot.test/api/health?plain=value',
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api:{$api->id}")
        ->assertOk()
        ->assertJsonPath('data.target', '//[redacted]@checkybot.test/api/health?plain=value')
        ->assertJsonPath('data.url', '//[redacted]@checkybot.test/api/health?plain=value')
        ->assertJsonPath('data.request_path', '//[redacted]@checkybot.test/api/health?plain=value');

    expect(json_encode($response->json()))->not->toContain('url-user')
        ->and(json_encode($response->json()))->not->toContain('url-pass')
        ->and(json_encode($response->json()))->not->toContain('path-user')
        ->and(json_encode($response->json()))->not->toContain('path-pass');
});

test('single check endpoint redacts sensitive query values when url parsing fails', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'title' => 'API health',
        'url' => 'https://url-user:url-pass@checkybot.test:abc/api/health?api_key=parse-secret&plain=value#fragment',
        'request_path' => 'https://path-user:path-pass@checkybot.test:abc/api/health?auth=parse-auth-secret&plain=value#fragment',
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/api:{$api->id}")
        ->assertOk()
        ->assertJsonPath('data.target', 'https://[redacted]@checkybot.test:abc/api/health?api_key=%5Bredacted%5D&plain=value#fragment')
        ->assertJsonPath('data.url', 'https://[redacted]@checkybot.test:abc/api/health?api_key=%5Bredacted%5D&plain=value#fragment')
        ->assertJsonPath('data.request_path', 'https://[redacted]@checkybot.test:abc/api/health?auth=%5Bredacted%5D&plain=value#fragment');

    expect(json_encode($response->json()))->not->toContain('url-user')
        ->and(json_encode($response->json()))->not->toContain('url-pass')
        ->and(json_encode($response->json()))->not->toContain('path-user')
        ->and(json_encode($response->json()))->not->toContain('path-pass')
        ->and(json_encode($response->json()))->not->toContain('parse-secret')
        ->and(json_encode($response->json()))->not->toContain('parse-auth-secret');
});

test('single check endpoints support url encoded package keys with dots and spaces', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'api.health check',
        'title' => 'API health check',
    ]);

    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $api->id,
    ]);

    $encodedKey = rawurlencode('api.health check');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/{$encodedKey}")
        ->assertOk()
        ->assertJsonPath('data.key', 'api.health check');

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}/checks/{$encodedKey}/results")
        ->assertOk()
        ->assertJsonPath('data.0.check_id', "api:{$api->id}");
});
