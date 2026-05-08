<?php

use App\Models\PloiAccounts;
use App\Models\PloiServers;
use App\Models\PloiWebsites;
use App\Services\PloiSiteImportService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = $this->actingAsUser();
    $this->account = PloiAccounts::factory()->create([
        'created_by' => $this->user->id,
        'key' => 'test-api-key-123',
    ]);
});

test('imports sites from all servers', function () {
    $server = PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 123,
        'created_by' => $this->user->id,
    ]);

    Http::fake([
        'ploi.io/api/servers/123/sites*' => Http::response([
            'data' => [
                [
                    'id' => 456,
                    'status' => 'active',
                    'domain' => 'example.com',
                    'deploy_script' => true,
                    'web_directory' => '/public',
                    'project_type' => 'laravel',
                    'project_root' => '/var/www',
                    'last_deploy_at' => '2025-01-01 12:00:00',
                    'system_user' => 'ploi',
                    'php_version' => '8.3',
                    'health_url' => 'https://example.com/health',
                    'notification_urls' => ['https://example.com/notify'],
                    'has_repository' => true,
                    'created_at' => '2024-01-01 12:00:00',
                ],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $service = new PloiSiteImportService($this->account);
    $imported = $service->import();

    expect($imported)->toBe(1);
    assertDatabaseHas('ploi_websites', [
        'site_id' => 456,
        'server_id' => 123,
        'domain' => 'example.com',
        'ploi_account_id' => $this->account->id,
        'created_by' => $this->user->id,
    ]);
});

test('imports sites from specific server', function () {
    $server = PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 123,
        'created_by' => $this->user->id,
    ]);

    Http::fake([
        'ploi.io/api/servers/123/sites*' => Http::response([
            'data' => [
                [
                    'id' => 789,
                    'status' => 'active',
                    'domain' => 'test.com',
                ],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $service = new PloiSiteImportService($this->account);
    $imported = $service->import($server);

    expect($imported)->toBe(1);
    assertDatabaseHas('ploi_websites', [
        'site_id' => 789,
        'server_id' => 123,
        'domain' => 'test.com',
    ]);
});

test('handles pagination correctly', function () {
    $server = PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 123,
        'created_by' => $this->user->id,
    ]);

    Http::fake([
        'ploi.io/api/servers/123/sites?per_page=50&page=1' => Http::response([
            'data' => [
                ['id' => 1, 'status' => 'active', 'domain' => 'site1.com'],
                ['id' => 2, 'status' => 'active', 'domain' => 'site2.com'],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 2,
            ],
        ], 200),
        'ploi.io/api/servers/123/sites?per_page=50&page=2' => Http::response([
            'data' => [
                ['id' => 3, 'status' => 'active', 'domain' => 'site3.com'],
            ],
            'meta' => [
                'current_page' => 2,
                'last_page' => 2,
            ],
        ], 200),
    ]);

    $service = new PloiSiteImportService($this->account);
    $imported = $service->import($server);

    expect($imported)->toBe(3);
    assertDatabaseHas('ploi_websites', ['site_id' => 1]);
    assertDatabaseHas('ploi_websites', ['site_id' => 2]);
    assertDatabaseHas('ploi_websites', ['site_id' => 3]);
});

test('updates existing sites', function () {
    $server = PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 123,
        'created_by' => $this->user->id,
    ]);

    PloiWebsites::create([
        'site_id' => 456,
        'server_id' => 123,
        'domain' => 'old-domain.com',
        'status' => 'inactive',
        'ploi_account_id' => $this->account->id,
        'created_by' => $this->user->id,
    ]);

    Http::fake([
        'ploi.io/api/servers/123/sites*' => Http::response([
            'data' => [
                [
                    'id' => 456,
                    'status' => 'active',
                    'domain' => 'new-domain.com',
                ],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $service = new PloiSiteImportService($this->account);
    $imported = $service->import($server);

    expect($imported)->toBe(1);
    assertDatabaseHas('ploi_websites', [
        'site_id' => 456,
        'domain' => 'new-domain.com',
        'status' => 'active',
    ]);
});

test('records api failures without stopping the import', function () {
    $server = PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 123,
        'created_by' => $this->user->id,
    ]);

    Http::fake([
        'ploi.io/api/servers/123/sites*' => Http::response([
            'error' => 'Unauthorized',
        ], 401),
    ]);

    $service = new PloiSiteImportService($this->account);
    $summary = $service->importWithSummary($server);

    expect($summary['imported'])->toBe(0)
        ->and($summary['imported_servers'])->toBe(0)
        ->and($summary['skipped_servers'])->toBe(0)
        ->and($summary['failed_servers'])->toBe(1)
        ->and($summary['failures'][0]['server_id'])->toBe(123)
        ->and($summary['failures'][0]['message'])->toContain('Failed to fetch sites for server 123');
});

test('uses correct api token', function () {
    $server = PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 123,
        'created_by' => $this->user->id,
    ]);

    Http::fake();

    $service = new PloiSiteImportService($this->account);
    $service->import($server);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer test-api-key-123');
    });
});

test('imports sites from multiple servers', function () {
    $server1 = PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 100,
        'created_by' => $this->user->id,
    ]);

    $server2 = PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 200,
        'created_by' => $this->user->id,
    ]);

    Http::fake([
        'ploi.io/api/servers/100/sites*' => Http::response([
            'data' => [
                ['id' => 1, 'status' => 'active', 'domain' => 'server1-site.com'],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
        'ploi.io/api/servers/200/sites*' => Http::response([
            'data' => [
                ['id' => 2, 'status' => 'active', 'domain' => 'server2-site.com'],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $service = new PloiSiteImportService($this->account);
    $imported = $service->import();

    expect($imported)->toBe(2);
    assertDatabaseHas('ploi_websites', ['site_id' => 1, 'server_id' => 100]);
    assertDatabaseHas('ploi_websites', ['site_id' => 2, 'server_id' => 200]);
});

test('continues importing healthy servers when another server sites endpoint fails', function () {
    PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 100,
        'name' => 'Healthy Server',
        'created_by' => $this->user->id,
    ]);

    PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 200,
        'name' => 'Failing Server',
        'created_by' => $this->user->id,
    ]);

    PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 300,
        'name' => 'Empty Server',
        'created_by' => $this->user->id,
    ]);

    Http::fake([
        'ploi.io/api/servers/100/sites*' => Http::response([
            'data' => [
                ['id' => 1, 'status' => 'active', 'domain' => 'server1-site.com'],
                ['id' => 2, 'status' => 'active', 'domain' => 'server1-second-site.com'],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
        'ploi.io/api/servers/200/sites*' => Http::response([
            'error' => 'Temporary Ploi failure',
        ], 503),
        'ploi.io/api/servers/300/sites*' => Http::response([
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $service = new PloiSiteImportService($this->account);
    $summary = $service->importWithSummary();

    expect($summary['imported'])->toBe(2)
        ->and($summary['imported_servers'])->toBe(1)
        ->and($summary['skipped_servers'])->toBe(1)
        ->and($summary['failed_servers'])->toBe(1)
        ->and($summary['failures'][0]['server_id'])->toBe(200)
        ->and($summary['failures'][0]['server_name'])->toBe('Failing Server');

    assertDatabaseHas('ploi_websites', ['site_id' => 1, 'server_id' => 100]);
    assertDatabaseHas('ploi_websites', ['site_id' => 2, 'server_id' => 100]);
});

test('handles empty response data', function () {
    $server = PloiServers::factory()->create([
        'ploi_account_id' => $this->account->id,
        'server_id' => 123,
        'created_by' => $this->user->id,
    ]);

    Http::fake([
        'ploi.io/api/servers/123/sites*' => Http::response([
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $service = new PloiSiteImportService($this->account);
    $imported = $service->import($server);

    expect($imported)->toBe(0);
});

test('formats ploi site import summary with server counts', function () {
    $summary = [
        'imported' => 2,
        'imported_servers' => 1,
        'skipped_servers' => 1,
        'failed_servers' => 1,
        'failures' => [
            [
                'server_id' => 200,
                'server_name' => 'Failing Server',
                'message' => 'Failed to fetch sites for server 200',
            ],
        ],
    ];

    expect(PloiSiteImportService::formatImportSummary($summary))
        ->toBe('Imported/updated 2 sites. 1 server imported. 1 server skipped. 1 server failed. Failed servers: Failing Server.');
});
