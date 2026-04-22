<?php

use App\Models\ApiKey;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Project;
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

    $response = $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$this->project->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->project->id)
        ->assertJsonPath('data.key', 'checkybot-app')
        ->assertJsonPath('data.name', 'Checkybot App')
        ->assertJsonPath('data.environment', 'production')
        ->assertJsonPath('data.checks_count', 2)
        ->assertJsonPath('data.api_checks_count', 1)
        ->assertJsonPath('data.website_checks_count', 1);

    expect(json_encode($response->json()))->not->toContain($this->project->token);
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
        'url' => 'https://checkybot.test/api/health?token=url-secret&api_key=query-secret&plain=value',
        'http_method' => 'GET',
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer secret-token',
            'Cookie' => 'session=cookie-secret',
            'Proxy-Authorization' => 'Basic proxy-secret',
            'X-Api-Key' => 'secret-api-key',
        ],
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
        ->assertJsonPath('data.0.target', 'https://checkybot.test/api/health?token=%5Bredacted%5D&api_key=%5Bredacted%5D&plain=value')
        ->assertJsonPath('data.0.interval', '5m')
        ->assertJsonPath('data.0.enabled', true)
        ->assertJsonPath('data.0.status', 'danger')
        ->assertJsonPath('data.0.headers.Authorization', '[redacted]')
        ->assertJsonPath('data.0.headers.Cookie', '[redacted]')
        ->assertJsonPath('data.0.headers.Proxy-Authorization', '[redacted]')
        ->assertJsonPath('data.0.headers.X-Api-Key', '[redacted]')
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
        ->and(json_encode($response->json()))->not->toContain('query-secret');
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
